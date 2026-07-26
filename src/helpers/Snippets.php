<?php

namespace bensomething\wahlberg\helpers;

use bensomething\wahlberg\events\RegisterSnippetsEvent;
use Craft;
use craft\helpers\StringHelper;
use yii\base\Event;

/**
 * The snippets a Markdown field can offer, read from `config/wahlberg.php`.
 *
 * ```php
 * return [
 *     'snippets' => [
 *         'callout' => [
 *             'label' => 'Callout',
 *             'body' => "> **Note**\n> \$0\n",
 *         ],
 *         // Shorthand: a body on its own, labelled from its key
 *         'figure' => "![\$SELECTION]({asset::url})\n*\$0*",
 *     ],
 * ];
 * ```
 *
 * A config file rather than a settings screen, on purpose, and for the same reason
 * `config/htmlpurifier/` is one: a snippet is a contract with the templates and CSS
 * that render it. A callout only looks like a callout because the front end styles
 * what it emits, so the person who should be writing one is the person who can also
 * write that. Which of them a given field offers is a field setting, the same split
 * the purifier configs already use.
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
     * Where the caret ends up once a snippet is inserted. The first one wins; a
     * snippet without one leaves the caret at the end.
     */
    public const CARET = '$0';

    /**
     * Replaced by whatever was selected when the snippet was chosen, so a snippet
     * can wrap the author’s text rather than only ever being dropped in beside it.
     * Empty when nothing was selected.
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

        // Plugins get theirs in first, so a handle the installation has defined
        // for itself wins over one a plugin brought with it
        $event = new RegisterSnippetsEvent();
        Event::trigger(self::class, self::EVENT_REGISTER_SNIPPETS, $event);

        $defined = array_merge($event->snippets, is_array($defined) ? $defined : []);

        return self::$snippets = self::normalize($defined);
    }

    /**
     * Turns whatever was defined into `handle => [label, body]` pairs, dropping
     * anything that couldn’t be inserted.
     *
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

            // Nothing to insert isn't a snippet, and a button that does nothing is
            // worse than no button
            if (!is_string($body) || $body === '') {
                continue;
            }

            $label = is_array($snippet) ? ($snippet['label'] ?? null) : null;
            $icon = is_array($snippet) ? ($snippet['icon'] ?? null) : null;

            $snippets[$handle] = [
                // Through `site`, the way Craft translates anything else an
                // installation names for itself
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
     * A readable name for a snippet that didn’t give itself one, out of whichever
     * convention its handle was written in: `figureCaption`, `figure-caption` and
     * `figure_caption` all come out as “Figure Caption”.
     */
    private static function labelFor(string $handle): string
    {
        $words = StringHelper::toWords(str_replace(['-', '_'], ' ', $handle), false, true);

        return StringHelper::titleize(implode(' ', $words));
    }

    /**
     * The snippets with the given handles, in the order they were defined in, so a
     * field’s toolbar reads the way the config file does however they were ticked.
     *
     * `*` is every one of them, which is what a field that has never been saved
     * against a snippet gets.
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
