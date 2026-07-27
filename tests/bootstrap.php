<?php

/**
 * Stands a fake Craft up in place of a real one. See {@see \bensomething\wahlberg\tests\support\Application}
 * for why, and {@see \bensomething\wahlberg\tests\TestCase} for what each test gets on top.
 */

use bensomething\wahlberg\tests\support\Application;
use bensomething\wahlberg\tests\support\Dir;
use craft\helpers\Markdown as MarkdownHelper;
use craft\markdown\GithubMarkdown;
use craft\markdown\Markdown;
use craft\markdown\MarkdownExtra;
use craft\markdown\PreEncodedMarkdown;
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
Dir::create($tmp . '/translations');

new Application([
    'id' => 'craft-wahlberg-tests',
    // Craft's own source tree, not the plugin's. Yii turns this into `@app`, which is
    // where `MarkdownField::icon()` looks for Font Awesome's Markdown mark.
    'basePath' => $root . '/vendor/craftcms/cms/src',
    'vendorPath' => $root . '/vendor',
    // HTML Purifier writes its compiled definitions here, by way of Yii's helper.
    'runtimePath' => $tmp . '/storage/runtime',
    'aliases' => [
        // Where `Cp::iconSvg()` looks: Craft's bootstrap points this at the solid
        // set, and without it every icon comes back as an empty string
        '@appicons' => $root . '/vendor/craftcms/cms/src/icons/solid',
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
                // What Craft translates anything an installation names for itself
                // through — a section, a field, a snippet in `config/wahlberg.php`.
                // Pointed at an empty directory, so those come back as written.
                'site' => [
                    'class' => PhpMessageSource::class,
                    'basePath' => $tmp . '/translations',
                ],
            ],
        ],
    ],
]);

// Craft swaps Yii's parsers for its own on boot, and adds `pre-encoded` on top of
// Yii's four. The fake application doesn't run that, so do it here: the flavour a
// field with Encode HTML on resolves to only exists once this has happened.
//
// Kept in step with `craft\base\ApplicationTrait::_postInit()`.
foreach ([
    'original' => Markdown::class,
    'pre-encoded' => PreEncodedMarkdown::class,
    'gfm' => GithubMarkdown::class,
    'gfm-comment' => GithubMarkdown::class,
    'extra' => MarkdownExtra::class,
] as $flavour => $class) {
    if (!isset(MarkdownHelper::$flavors[$flavour]) || !is_object(MarkdownHelper::$flavors[$flavour])) {
        MarkdownHelper::$flavors[$flavour]['class'] = $class;
    }
}
