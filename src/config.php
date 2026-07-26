<?php

/**
 * Wahlberg config
 *
 * Copy this to `config/wahlberg.php` and edit it there. Nothing here is required:
 * without the file, Markdown fields work as they always have, and the Snippets
 * button hides itself rather than opening an empty menu.
 *
 * Like the rest of Craft’s config files, the whole thing can be nested under
 * environment names — `dev`, `staging`, `production`, `*` — if the snippets an
 * installation offers should differ by environment.
 */

return [
    /**
     * Blocks of Markdown authors can drop in from the toolbar’s Snippets button.
     *
     * These live in config rather than in a settings screen because a snippet is a
     * contract with the templates and CSS that render it: a callout only looks like
     * a callout because the front end styles what it emits. Which of them a given
     * field offers is a field setting, the same split `config/htmlpurifier/` uses.
     *
     * Each snippet takes a `label`, a `body`, and an optional `icon` — any name
     * from Craft’s own set, which is Font Awesome’s solid icons. One that doesn’t
     * name an icon gets a neutral stand-in, so the labels line up either way.
     *
     * Mind what your fields are set to render. With **Purify HTML** on, which is
     * the default, raw HTML in a snippet is sanitised on the way out, and the
     * purifier only knows HTML 4 — `<details>` and `<summary>` are dropped, iframes
     * survive only for the hosts `config/htmlpurifier/` allows. Markdown inside a
     * raw HTML block isn’t parsed either, whatever the purifier does. A snippet
     * that emits Markdown works everywhere; one that emits HTML is worth checking
     * in the Preview tab first.
     *
     * Two markers are understood, and both are optional:
     *
     * - `$0` is where the caret ends up. Without one it lands at the end.
     * - `$SELECTION` is replaced by whatever the author had selected, so a snippet
     *   can wrap their text rather than only ever being dropped in beside it. It’s
     *   empty when nothing was selected, and every occurrence is replaced.
     *
     * Mind the quoting: in a double-quoted PHP string `$0` and `$SELECTION` would
     * be read as variables, so either escape them as below or use single quotes and
     * `\n` won’t be a newline. Heredocs interpolate too; nowdocs (`<<<'MD'`) don’t.
     */
    'snippets' => [
        // The long form, for a snippet that wants a label and an icon of its own
        'callout' => [
            'label' => 'Callout',
            'icon' => 'circle-info',
            'body' => "> **Note**\n> \$0\n",
        ],

        // Wraps the selection as the alt text and leaves the caret where the URL
        // goes. The toolbar's Asset button is the better way in when the image is
        // already in a volume; this is for one that isn't
        'figure' => [
            'label' => 'Figure with caption',
            'icon' => 'image',
            'body' => "![\$SELECTION](\$0)\n*Caption*\n",
        ],

        'pullQuote' => [
            'label' => 'Quote with attribution',
            'icon' => 'block-quote',
            'body' => "> \$SELECTION\$0\n>\n> — **Name**, Title\n",
        ],

        // Survives purification because Craft's defaults allow YouTube and Vimeo
        // iframes through. Anywhere else needs an `HTML.SafeIframe` config in
        // `config/htmlpurifier/`
        'videoEmbed' => [
            'label' => 'Video embed',
            'icon' => 'video',
            'body' => "<iframe src=\"https://www.youtube.com/embed/\$0\" allowfullscreen></iframe>\n",
        ],

        'table' => [
            'label' => 'Table',
            'icon' => 'table',
            'body' => "| \$0 | |\n| --- | --- |\n| | |\n",
        ],
    ],
];
