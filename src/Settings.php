<?php

declare(strict_types=1);

namespace bensomething\wahlberg;

use CraftCms\Cms\Plugin\PluginSettings;
use Override;

/**
 * Wahlberg's settings, saved to project config from Settings → Plugins → Wahlberg.
 *
 * Project config is in version control, which is what 1.x's config file was for: a
 * snippet is a contract with the templates and CSS that render it. A key set in
 * `config/craft/wahlberg.php` replaces the saved one wholesale, and shows read-only
 * on the settings screen.
 */
class Settings extends PluginSettings
{
    /** The character that opens the snippets menu. */
    public string $trigger = Snippets::DEFAULT_TRIGGER;

    /** Whether typing `@` in a Markdown field offers links to entries. */
    public bool $entryLinks = true;

    /**
     * Rows from the settings screen, each with a `label`, `handle`, `body`, `inline`
     * and `fields`. The config file's handle-keyed map is accepted here too, since it
     * lands on this property when it overrides it.
     *
     * @var array<array-key, mixed>
     */
    public array $snippets = [];

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            // Unquoted: Rule::in() quotes each value, and Laravel reads the rule back
            // with str_getcsv(), which takes a backslash inside quotes for an escape
            'trigger' => ['required', 'string', 'in:'.implode(',', Snippets::TRIGGERS)],
            'entryLinks' => ['boolean'],
            'snippets' => ['array'],
            'snippets.*.label' => ['nullable', 'string'],
            'snippets.*.handle' => ['nullable', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/', 'distinct'],
            'snippets.*.body' => ['required', 'string'],
            'snippets.*.inline' => ['nullable', 'boolean'],
        ]);
    }
}
