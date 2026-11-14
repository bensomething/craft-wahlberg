<?php

declare(strict_types=1);

namespace bensomething\wahlberg;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;

/**
 * Wahlberg: snippets for Craft's Markdown field, Craft 6 line.
 *
 * Craft 6 ships a Markdown field of its own, with a toolbar, a server-rendered
 * preview and reference tags, so 2.x doesn't bring a field type. It adds
 * config-defined snippets to the native field instead, on the `/` menu its text
 * expander already has room for. 1.x, on Craft 5, is the full field.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '2.0.0';
}
