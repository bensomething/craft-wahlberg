<?php

declare(strict_types=1);

namespace bensomething\wahlberg\tests\Feature;

use bensomething\wahlberg\Plugin;
use bensomething\wahlberg\tests\TestCase;
use CraftCms\Cms\Plugin\Plugins;

/** How Wahlberg boots, and that it comes and goes cleanly. */
final class PluginTest extends TestCase
{
    public function test_it_is_installed_and_enabled(): void
    {
        $plugins = app(Plugins::class);

        $this->assertTrue($plugins->isPluginInstalled('wahlberg'));
        $this->assertTrue($plugins->isPluginEnabled('wahlberg'));
        $this->assertInstanceOf(Plugin::class, Plugin::getInstance());
    }

    public function test_every_listed_asset_exists(): void
    {
        $assets = (fn () => [...$this->styles, ...$this->scripts])->call(Plugin::getInstance());
        $this->assertNotEmpty($assets);

        foreach (array_keys($assets) as $source) {
            $this->assertFileExists($source);
        }
    }

    public function test_it_uninstalls_and_reinstalls_cleanly(): void
    {
        $plugins = app(Plugins::class);

        $this->assertTrue($plugins->uninstallPlugin('wahlberg'));
        $this->assertFalse($plugins->isPluginInstalled('wahlberg'));

        $this->assertTrue($plugins->installPlugin('wahlberg'));
        $this->assertTrue($plugins->isPluginInstalled('wahlberg'));
    }
}
