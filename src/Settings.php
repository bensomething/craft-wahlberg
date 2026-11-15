<?php

declare(strict_types=1);

namespace bensomething\wahlberg;

use Closure;
use CraftCms\Cms\Plugin\PluginSettings;
use Override;

use function CraftCms\Cms\t;

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

    /**
     * Rows from the settings screen, each with a `label`, `handle`, `body`, `inline`
     * and `fields`. The config file's handle-keyed map is accepted here too, since it
     * lands on this property when it overrides it.
     *
     * @var array<array-key, mixed>
     */
    public array $snippets = [];

    /**
     * The writing checks that are on: `*` for all of them, which includes any added
     * later, or a list of handles.
     *
     * @var string|list<string>
     */
    public string|array $checks = WritingChecks::EVERY;

    /**
     * The checks as they're saved: `*` for All, which the settings screen posts as
     * `['*']`, and a list otherwise. With every box unticked it posts an empty string,
     * which saves as none.
     */
    #[Override]
    public function validationData(): array
    {
        $data = parent::validationData();
        $data['checks'] = self::normalizeChecks($data['checks'] ?? WritingChecks::EVERY);

        return $data;
    }

    /** @return string|list<mixed> */
    public static function normalizeChecks(mixed $checks): string|array
    {
        if ($checks === WritingChecks::EVERY || (is_array($checks) && in_array(WritingChecks::EVERY, $checks, true))) {
            return WritingChecks::EVERY;
        }

        return is_array($checks) ? array_values(array_filter($checks, fn (mixed $check) => $check !== '')) : ($checks === '' ? [] : $checks);
    }

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            // Unquoted: Rule::in() quotes each value, and Laravel reads the rule back
            // with str_getcsv(), which takes a backslash inside quotes for an escape
            'trigger' => ['required', 'string', 'in:'.implode(',', Snippets::TRIGGERS)],
            'snippets' => ['array'],
            'snippets.*.label' => ['nullable', 'string'],
            'snippets.*.handle' => ['nullable', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/', 'distinct'],
            'snippets.*.body' => ['required', 'string'],
            'snippets.*.inline' => ['nullable', 'boolean'],
            'checks' => [function (string $attribute, mixed $value, Closure $fail): void {
                $valid = $value === WritingChecks::EVERY
                    || (is_array($value) && array_diff($value, WritingChecks::ALL) === []);

                if (! $valid) {
                    $fail(t('Choose writing checks from the list.', category: 'wahlberg'));
                }
            }],
        ]);
    }
}
