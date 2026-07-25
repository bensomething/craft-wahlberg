<?php

namespace bensomething\wahlberg\tests;

use bensomething\wahlberg\tests\support\Dir;
use Craft;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * An empty `config/htmlpurifier/` per test, since several of them write a config file
 * into it and the purifier caches nothing between runs.
 */
abstract class TestCase extends BaseTestCase
{
    protected string $purifierConfigPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purifierConfigPath = Craft::$app->getPath()->getConfigPath() . '/htmlpurifier';

        Dir::remove($this->purifierConfigPath);
        Dir::create($this->purifierConfigPath);
    }

    protected function tearDown(): void
    {
        Dir::remove($this->purifierConfigPath);

        parent::tearDown();
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
