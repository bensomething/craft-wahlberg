<?php

/**
 * Stands a fake Craft up in place of a real one. See {@see \bensomething\wahlberg\tests\support\Application}
 * for why, and {@see \bensomething\wahlberg\tests\TestCase} for what each test gets on top.
 */

use bensomething\wahlberg\tests\support\Application;
use bensomething\wahlberg\tests\support\Dir;
use craft\services\Config;
use craft\services\Path;
use yii\i18n\PhpMessageSource;

define('YII_DEBUG', true);

// Yii's handler would swallow the failures PHPUnit is here to report.
define('YII_ENABLE_ERROR_HANDLER', false);

$root = dirname(__DIR__);

// Craft and Yii both trip PHP 8.4's implicit-nullable deprecation as they load, and
// that's their business, not this suite's. Wahlberg's own deprecations are reported by
// PHPUnit, which scopes them to `src` — see phpunit.xml.
$reporting = error_reporting(E_ALL & ~E_DEPRECATED);

require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
require $root . '/vendor/craftcms/cms/src/Craft.php';

error_reporting($reporting);

// One tree per run. Tests make their own directories inside it; this only has to exist
// and be empty at the start.
//
// Built with plain `mkdir()` rather than Craft's FileHelper, which reads its directory
// mode off the general config, and there isn't one.
$tmp = sys_get_temp_dir() . '/craft-wahlberg-tests';

Dir::remove($tmp);
Dir::create($tmp);
Dir::create($tmp . '/config');
Dir::create($tmp . '/storage/runtime');

new Application([
    'id' => 'craft-wahlberg-tests',
    // Craft's own source tree, not the plugin's. Yii turns this into `@app`, which is
    // where `MarkdownField::icon()` looks for Font Awesome's Markdown mark.
    'basePath' => $root . '/vendor/craftcms/cms/src',
    'vendorPath' => $root . '/vendor',
    // HTML Purifier writes its compiled definitions here, by way of Yii's helper.
    'runtimePath' => $tmp . '/storage/runtime',
    'aliases' => [
        '@root' => $tmp,
        '@config' => $tmp . '/config',
        '@storage' => $tmp . '/storage',
    ],
    'components' => [
        'config' => ['class' => Config::class, 'configDir' => $tmp . '/config'],
        'path' => ['class' => Path::class],
        'i18n' => [
            'translations' => [
                // `forceTranslation` off, so an untranslated string comes back as its
                // source and assertions can be written in English.
                'wahlberg' => [
                    'class' => PhpMessageSource::class,
                    'basePath' => $root . '/src/translations',
                ],
                'app' => [
                    'class' => PhpMessageSource::class,
                    'basePath' => '@yii/messages',
                ],
            ],
        ],
    ],
]);
