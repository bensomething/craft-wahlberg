<?php

namespace bensomething\wahlberg\tests\support;

use craft\services\Config;
use craft\services\Path;
use yii\console\Application as ConsoleApplication;

/**
 * A stand-in for `Craft::$app`.
 *
 * Wahlberg has no database, no migrations and no elements of its own, so booting a real
 * Craft to test it would mean standing up MySQL in CI for code that never issues a
 * query. This is a Yii console application with the few methods Craft's own classes
 * reach for filled in — enough to construct the field, parse and purify Markdown, and
 * read a purifier config off disk.
 *
 * Anything not implemented here throws an unknown-method error rather than answering
 * wrongly, so a test straying into territory this can't honestly fake fails loudly.
 */
class Application extends ConsoleApplication
{
    /**
     * Craft's base classes ask on construction, and `Craft::autoload()` asks before
     * generating `CustomFieldBehavior`. False gets the empty in-memory behaviour, which
     * is what a plugin with no custom fields wants.
     */
    public function getIsInstalled(bool $strict = false): bool
    {
        return false;
    }

    /**
     * Real, but pointed at an empty config directory, so it answers with stock defaults.
     */
    public function getConfig(): Config
    {
        /** @var Config $component */
        $component = $this->get('config');

        return $component;
    }

    /**
     * Craft's path service. {@see \bensomething\wahlberg\helpers\Purifier} asks it where
     * `config/htmlpurifier/` is.
     */
    public function getPath(): Path
    {
        /** @var Path $component */
        $component = $this->get('path');

        return $component;
    }
}
