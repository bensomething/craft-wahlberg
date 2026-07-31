<?php

namespace bensomething\wahlberg\helpers;

use bensomething\wahlberg\events\RegisterSnippetsEvent;
use Craft;
use craft\helpers\StringHelper;
use yii\base\Event;

/**
 * The snippets a Markdown field can offer, read from `config/wahlberg.php`.
 *
 * A config file rather than a settings screen for the same reason
 * `config/htmlpurifier/` is one: a snippet is a contract with the templates and CSS
 * that render it, so it belongs to whoever can write those, in version control.
 * Which of them a given field offers is a field setting.
 *
 * Plugins can add their own through [[EVENT_REGISTER_SNIPPETS]].
 */
abstract class Snippets
{
    /**
     * @event RegisterSnippetsEvent Raised so plugins can add snippets of their own.
     */
    public const EVENT_REGISTER_SNIPPETS = 'registerSnippets';

    public const CONFIG_FILE = 'wahlberg';

    /**
     * Where the caret ends up, and the last of the stops a body can carry: `$1`
     * through `$9` are visited in order first, on Tab, and `$0` is where the run
     * finishes. Without one the caret goes to the end.
     *
     * The numbering is the convention every editor with snippets uses, and it’s why
     * `$0` still means what it meant when it was the only marker there was — a body
     * carrying nothing else behaves exactly as it always has.
     */
    public const CARET = '$0';

    /**
     * Replaced by whatever was selected, so a snippet can wrap an author’s text
     * rather than only ever landing beside it. Empty when nothing was selected.
     */
    public const SELECTION = '$SELECTION';

    /**
     * @var array<string, array{label: string, body: string, icon: string|null}>|null
     */
    private static ?array $snippets = null;

    /**
     * Every snippet defined, keyed by handle.
     *
     * @return array<string, array{label: string, body: string, icon: string|null}>
     */
    public static function all(): array
    {
        if (self::$snippets !== null) {
            return self::$snippets;
        }

        $config = Craft::$app->getConfig()->getConfigFromFile(self::CONFIG_FILE);
        $defined = is_array($config) ? ($config['snippets'] ?? []) : [];

        $event = new RegisterSnippetsEvent();
        Event::trigger(self::class, self::EVENT_REGISTER_SNIPPETS, $event);

        // Plugins first, so a handle the installation defined for itself wins
        $defined = array_merge($event->snippets, is_array($defined) ? $defined : []);

        return self::$snippets = self::normalize($defined);
    }

    /**
     * @param array<mixed> $defined
     * @return array<string, array{label: string, body: string, icon: string|null}>
     */
    private static function normalize(array $defined): array
    {
        $snippets = [];

        foreach ($defined as $handle => $snippet) {
            // A list rather than a map, so there are no handles to key by
            if (!is_string($handle)) {
                continue;
            }

            $body = is_array($snippet) ? ($snippet['body'] ?? null) : $snippet;

            if (!is_string($body) || $body === '') {
                continue;
            }

            $label = is_array($snippet) ? ($snippet['label'] ?? null) : null;
            $icon = is_array($snippet) ? ($snippet['icon'] ?? null) : null;

            $snippets[$handle] = [
                'label' => Craft::t('site', is_string($label) && $label !== ''
                    ? $label
                    : self::labelFor($handle)),
                'body' => $body,
                'icon' => is_string($icon) && $icon !== '' ? $icon : null,
            ];
        }

        return $snippets;
    }

    /**
     * `figureCaption`, `figure-caption` and `figure_caption` all give “Figure Caption”.
     */
    private static function labelFor(string $handle): string
    {
        $words = StringHelper::toWords(str_replace(['-', '_'], ' ', $handle), false, true);

        return StringHelper::titleize(implode(' ', $words));
    }

    /**
     * The given snippets, in the order the config file defined them. `*` is all of
     * them, which is what a field that has never been saved against one gets.
     *
     * @param string|list<string> $handles
     * @return array<string, array{label: string, body: string, icon: string|null}>
     */
    public static function only(string|array $handles): array
    {
        $all = self::all();

        if (!is_array($handles)) {
            return $all;
        }

        return array_intersect_key($all, array_flip($handles));
    }

    /**
     * Handle/label pairs, for the field setting that picks between them.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn(array $snippet) => $snippet['label'], self::all());
    }

    /**
     * Forgets what was read, so a test can write a config file and see it.
     */
    public static function reset(): void
    {
        self::$snippets = null;
    }
}
