<?php

namespace bensomething\wahlberg\tests;

use bensomething\wahlberg\helpers\Snippets;
use bensomething\wahlberg\tests\support\Dir;
use Craft;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * An empty `config/htmlpurifier/` per test, since several of them write a config file
 * into it and the purifier caches nothing between runs. Likewise no `config/wahlberg.php`,
 * and the snippets helper’s memo cleared, so one test’s config can’t reach another.
 */
abstract class TestCase extends BaseTestCase
{
    protected string $purifierConfigPath;
    protected string $pluginConfigPath;

    protected function setUp(): void
    {
        parent::setUp();

        $configPath = Craft::$app->getPath()->getConfigPath();

        $this->purifierConfigPath = $configPath . '/htmlpurifier';
        $this->pluginConfigPath = $configPath . '/' . Snippets::CONFIG_FILE . '.php';

        Dir::remove($this->purifierConfigPath);
        Dir::create($this->purifierConfigPath);

        $this->clearPluginConfig();
    }

    protected function tearDown(): void
    {
        Dir::remove($this->purifierConfigPath);
        $this->clearPluginConfig();

        parent::tearDown();
    }

    /**
     * Writes a `config/wahlberg.php` for this test to read back.
     *
     * @param array<string, mixed> $config
     */
    protected function writePluginConfig(array $config): void
    {
        file_put_contents($this->pluginConfigPath, '<?php return ' . var_export($config, true) . ';');

        // The file is read once and remembered, which is what a real request wants
        // and what a suite writing a new one every test doesn't
        Snippets::reset();
    }

    protected function clearPluginConfig(): void
    {
        if (file_exists($this->pluginConfigPath)) {
            unlink($this->pluginConfigPath);
        }

        Snippets::reset();
    }

    /**
     * Writes an HTML Purifier config into this test's `config/htmlpurifier/`.
     *
     * @param array<string, mixed> $options
     */
    protected function writePurifierConfig(string $filename, array $options): string
    {
        $path = $this->purifierConfigPath . '/' . $filename;
        file_put_contents($path, json_encode($options, JSON_THROW_ON_ERROR));

        return $path;
    }
}
