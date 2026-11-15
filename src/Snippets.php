<?php

declare(strict_types=1);

namespace bensomething\wahlberg;

use bensomething\wahlberg\Events\SnippetsRegistering;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Support\Facades\Fields;
use Illuminate\Support\Str;

use function CraftCms\Cms\t;

/**
 * The snippets Markdown fields offer: those on the settings screen, or in
 * `config/craft/wahlberg.php` when that sets them, plus any a plugin adds through
 * {@see SnippetsRegistering}.
 *
 * @phpstan-type Snippet array{label: string, body: string, inline: bool, fields: list<string>|null}
 * @phpstan-type Stop array{int, int}
 * @phpstan-type Option array{label: string, value: string, keywords: list<string>, data: array{wahlberg: array{stops: list<Stop>, inline: bool}, hint?: string}}
 */
class Snippets
{
    public const string DEFAULT_TRIGGER = '/';

    /**
     * The characters the menu can open on. Most others mean something already: `#`,
     * `>`, `-`, `*` and `+` start Markdown blocks, `!` an image, `:` one of Craft's
     * emoji shortcodes, and `@` is taken by mentions and entry links.
     */
    public const array TRIGGERS = ['/', '\\', ';'];

    /**
     * `$0` through `$9`, bare or carrying a default: `${1:Name}`. No nested braces in
     * a default, so a default runs to the first `}`. The same rule as 1.x.
     */
    private const string STOP = '/\$(?:([0-9])|\{([0-9])(?::([^}]*))?\})/';

    /**
     * Replaced by the selection in 1.x. The `/` menu replaces what was typed to open
     * it rather than a selection, so there's never one to put in, and it goes.
     */
    private const string SELECTION = '$SELECTION';

    /** @var array<string, Snippet>|null */
    private ?array $snippets = null;

    public function __construct(
        private readonly Settings $settings,
    ) {}

    /**
     * The character that opens the menu. The config file isn't validated, so one it
     * sets outside the list falls back to the default.
     */
    public function trigger(): string
    {
        return in_array($this->settings->trigger, self::TRIGGERS, true) ? $this->settings->trigger : self::DEFAULT_TRIGGER;
    }

    /**
     * Every snippet defined, keyed by handle, in the order defined: plugins' first,
     * then the installation's own.
     *
     * @return array<string, Snippet>
     */
    public function all(): array
    {
        if ($this->snippets !== null) {
            return $this->snippets;
        }

        event($event = new SnippetsRegistering);

        // Plugins first, so a handle the installation defined for itself wins
        $defined = array_merge(
            $this->normalize($event->snippets),
            $this->normalize($this->settings->snippets),
        );

        return $this->snippets = $defined;
    }

    /**
     * The snippets a field offers, as text expander options.
     *
     * A snippet without `fields` goes on every Markdown field. One with `fields`
     * matches a handle either as the field layout names it or as the field itself
     * does, so a layout that renames a field doesn't lose its snippets.
     *
     * @return list<Option>
     */
    public function optionsFor(FieldInterface $field): array
    {
        $handles = array_filter([
            $field->handle,
            $field->id ? Fields::getFieldById((int) $field->id)?->handle : null,
        ]);

        $options = [];

        foreach ($this->all() as $handle => $snippet) {
            if ($snippet['fields'] !== null && ! array_intersect($handles, $snippet['fields'])) {
                continue;
            }

            ['text' => $text, 'stops' => $stops] = self::compile($snippet['body']);

            $data = ['wahlberg' => ['stops' => $stops, 'inline' => $snippet['inline']]];

            // Shown at the right of the option, so the author knows it won't start a
            // new paragraph
            if ($snippet['inline']) {
                $data['hint'] = t('Inline', category: 'wahlberg');
            }

            $options[] = [
                'label' => $snippet['label'],
                'value' => $text,
                'keywords' => [$handle],
                'data' => $data,
            ];
        }

        return $options;
    }

    /**
     * Takes the stops out of a body and says where each one was.
     *
     * Numbered order with `$0` last, and by position where a number is used twice. A
     * default stays in the text with its stop covering it, so landing on it selects
     * it. Offsets are in UTF-16 code units, which is what a textarea's selection
     * counts in, so they still line up after an emoji.
     *
     * @return array{text: string, stops: list<Stop>}
     */
    public static function compile(string $body): array
    {
        $body = str_replace(self::SELECTION, '', $body);

        preg_match_all(self::STOP, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $found = [];
        $text = '';
        $at = 0;

        foreach ($matches as $match) {
            $marker = (string) $match[0][0];
            $offset = $match[0][1];
            $number = $match[1][0] ?? $match[2][0];
            $fill = $match[3][0] ?? '';

            $text .= substr($body, $at, $offset - $at);
            $at = $offset + strlen($marker);

            $from = self::utf16Length($text);
            $found[] = [
                'order' => $number === '0' ? 10 : (int) $number,
                'from' => $from,
                'to' => $from + self::utf16Length($fill),
            ];

            $text .= $fill;
        }

        $text .= substr($body, $at);

        usort($found, fn (array $a, array $b) => [$a['order'], $a['from']] <=> [$b['order'], $b['from']]);

        return [
            'text' => $text,
            'stops' => array_map(fn (array $stop) => [$stop['from'], $stop['to']], $found),
        ];
    }

    /** Forgets what was read, so a test can change the config and see it. */
    public function reset(): void
    {
        $this->snippets = null;
    }

    /**
     * @param  array<mixed>  $defined
     * @return array<string, Snippet>
     */
    private function normalize(array $defined): array
    {
        $snippets = [];

        foreach ($defined as $key => $snippet) {
            $body = is_array($snippet) ? ($snippet['body'] ?? null) : $snippet;

            if (! is_string($body) || $body === '') {
                continue;
            }

            $label = is_array($snippet) ? ($snippet['label'] ?? null) : null;
            $label = is_string($label) && trim($label) !== '' ? trim($label) : null;

            // Keyed by handle in the config file. Rows from the settings screen carry
            // it in a column, and one left blank takes it from the label
            $handle = is_string($key) ? $key : (is_array($snippet) ? ($snippet['handle'] ?? null) : null);
            $handle = is_string($handle) && trim($handle) !== '' ? trim($handle) : ($label ? Str::camel($label) : null);

            if ($handle === null) {
                continue;
            }

            $snippets[$handle] = [
                'label' => t($label ?? Str::headline($handle), category: 'site'),
                'body' => $body,
                // Most snippets are blocks (a quote, a table, a figure) that need lines
                // of their own. An inline one goes in exactly where it was typed
                'inline' => is_array($snippet) && filter_var($snippet['inline'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'fields' => self::fields(is_array($snippet) ? ($snippet['fields'] ?? null) : null),
            ];
        }

        return $snippets;
    }

    /**
     * A list in the config file, and handles separated by commas or spaces on the
     * settings screen. None means every field.
     *
     * @return list<string>|null
     */
    private static function fields(mixed $fields): ?array
    {
        if (is_string($fields)) {
            $fields = preg_split('/[\s,]+/', $fields, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($fields)) {
            return null;
        }

        $fields = array_values(array_filter($fields, fn ($field) => is_string($field) && $field !== ''));

        return $fields === [] ? null : $fields;
    }

    private static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
