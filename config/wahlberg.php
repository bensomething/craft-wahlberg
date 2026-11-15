<?php

/**
 * Wahlberg config file.
 *
 * Everything here can be set under Settings → Plugins → Wahlberg instead, which saves
 * to project config. Use this file for values that should be fixed in code: a key set
 * here replaces the saved one entirely, and shows read-only on the settings screen.
 *
 * Publish it into your project with `php artisan vendor:publish --tag=wahlberg`,
 * which copies it to `config/craft/wahlberg.php`, and edit it there.
 *
 * Coming from Wahlberg 1.x on Craft 5: the file has moved from `config/wahlberg.php`
 * to `config/craft/wahlberg.php`, and Craft 5's per-environment keys (`'*'`, `'dev'`,
 * …) aren't read. Use `env()` for values that differ by environment. The snippet
 * format itself is unchanged.
 */

return [
    // The character that opens the snippets menu, typed at the start of a line or
    // after a space: '/', '\\' or ';'. Anything else falls back to '/'.
    // 'trigger' => '/',

    // Blocks of Markdown authors can drop in from the `/` menu, keyed by handle.
    // Each takes a `label` and a `body`, and optionally `fields`: the handles of the
    // Markdown fields that offer it. Without `fields`, every Markdown field does.
    // Picked mid-sentence, a snippet starts a new paragraph, since most are blocks.
    // `'inline' => true` puts one in exactly where it was typed instead.
    //
    // In a body, `$1` to `$9` are stops visited on Tab, `${1:like this}` is a stop
    // with a default, and `$0` is where the caret ends up. Escape the `$` in
    // double-quoted strings, or PHP reads them as variables.
    //
    // Uncommented, this replaces the snippets on the settings screen.
    // 'snippets' => [
    //     'callout' => [
    //         'label' => 'Callout',
    //         'body' => "> [!NOTE]\n> \${1:Text}\$0",
    //         'fields' => ['body'],
    //     ],
    // ],
];
