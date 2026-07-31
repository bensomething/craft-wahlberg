<?php

/**
 * Wahlberg config
 *
 * Copy this to `config/wahlberg.php`. Nothing here is required, and like Craft’s
 * own config files it can be nested under environment names.
 */

return [
    /**
     * Blocks of Markdown authors can drop in from the toolbar’s Snippets button.
     *
     * Each takes a `label`, a `body`, and an optional `icon` — any name from
     * Craft’s set. One without an icon gets a neutral stand-in, so labels line up
     * either way. Which snippets a given field offers is a field setting.
     *
     * Optional markers:
     *
     * - `$1` to `$9` are stops, visited in order on Tab and ⇧Tab. Esc ends the run.
     * - `$0` is where the caret ends up: the last stop, after any numbered ones.
     *   Without a marker at all it lands at the end.
     * - `$SELECTION` is replaced by what the author had selected, so a snippet can
     *   wrap their text. Empty when nothing was selected; every occurrence goes.
     *
     * Mind the quoting: in a double-quoted string PHP reads `$0` and `$SELECTION`
     * as variables, so escape them as below. Single quotes avoid that but cost you
     * `\n`. Heredocs interpolate; nowdocs (`<<<'MD'`) don’t.
     *
     * Mind the purifier too. With **Purify HTML** on, raw HTML in a snippet is
     * sanitised on the way out by a purifier that only knows HTML 4 — `<details>`
     * is dropped, iframes survive only for allowed hosts — and Markdown inside a
     * raw HTML block isn’t parsed at all. A snippet emitting Markdown works
     * everywhere; one emitting HTML is worth checking in the Preview tab.
     */
    'snippets' => [
        'callout' => [
            'label' => 'Callout',
            'icon' => 'circle-info',
            'body' => "> **Note**\n> \$0\n",
        ],

        // The Asset button is the better way in for an image already in a volume
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

        // Survives purification because Craft's defaults allow YouTube and Vimeo.
        // Anywhere else needs an `HTML.SafeIframe` config in `config/htmlpurifier/`
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
