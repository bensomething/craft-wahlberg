<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Console;

use bensomething\wahlberg\Upgrade\LegacyFields;
use Illuminate\Console\Command;

/**
 * Converts Wahlberg 1.x's fields to Craft's Markdown field, or with `--dry-run`
 * shows what converting would do.
 *
 * Updating from 1.x does this already, in a migration. This is for seeing it
 * coming, and for project config that brings 1.x fields back afterwards.
 */
class ConvertFieldsCommand extends Command
{
    protected $signature = 'wahlberg:convert-fields {--dry-run : Show what would change, without changing it}';

    protected $description = 'Convert Wahlberg 1.x Markdown fields to Craft’s Markdown field.';

    public function handle(LegacyFields $fields): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $snippetFields = $fields->snippetFields();
        $plan = $dryRun ? $fields->plan() : $fields->convert();

        if ($plan === []) {
            $this->components->info('There are no Wahlberg 1.x fields to convert.');

            return self::SUCCESS;
        }

        $this->components->info($dryRun
            ? 'These fields would become Craft’s Markdown field:'
            : 'These fields are now Craft’s Markdown field:');

        foreach (LegacyFields::report($plan, $snippetFields) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
