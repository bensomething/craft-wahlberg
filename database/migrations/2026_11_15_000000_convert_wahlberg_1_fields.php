<?php

declare(strict_types=1);

use bensomething\wahlberg\Upgrade\LegacyFields;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Coming from Wahlberg 1.x: its Markdown fields become Craft's own.
 *
 * Only where project config hasn't been through this already. On the other
 * environments, the converted fields arrive with the project config this writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $incoming = app(ProjectConfig::class)->get(ProjectConfig::PATH_PLUGINS.'.wahlberg.schemaVersion', true);

        if (is_string($incoming) && version_compare($incoming, '2.0.0', '>=')) {
            return;
        }

        $fields = app(LegacyFields::class);
        $snippetFields = $fields->snippetFields();
        $plan = $fields->convert();

        if ($plan === []) {
            return;
        }

        $lines = ['Wahlberg converted these fields to Craft’s Markdown field:', ...LegacyFields::report($plan, $snippetFields)];

        Log::info(implode("\n", $lines));

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln($lines);
        }
    }

    public function down(): void
    {
        // 1.x's field type is gone with 1.x, so there's nothing to go back to
    }
};
