<?php

declare(strict_types=1);

namespace bensomething\wahlberg\tests;

use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * Boots Craft 6 on an in-memory SQLite database, runs its install, and installs
 * Wahlberg from this package's composer.json, through Craft's own plugin test case.
 */
abstract class TestCase extends PluginTestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite.database', ':memory:');

        // Craft's model factories, mapped in composer.json's autoload-dev, are named
        // for their models but not under Laravel's default `Database\Factories`
        Factory::guessFactoryNamesUsing(
            fn (string $model) => 'CraftCms\\Cms\\Database\\Factories\\'.class_basename($model).'Factory',
        );
    }
}
