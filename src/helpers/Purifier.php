<?php

namespace bensomething\wahlberg\helpers;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\HtmlPurifier;
use craft\helpers\Json;
use HTMLPurifier_Config;

/**
 * Runs parsed Markdown through HTML Purifier, using the same `config/htmlpurifier/`
 * convention as Craft’s own HTML fields.
 */
abstract class Purifier
{
    public const CONFIG_DIR = 'htmlpurifier';
    public const DEFAULT_CONFIG_FILE = 'Default.json';

    public static function process(string $html, ?string $configFile = null): string
    {
        return HtmlPurifier::process($html, self::config($configFile));
    }

    public static function config(?string $configFile = null): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->autoFinalize = false;

        foreach (self::options($configFile) ?? self::defaultOptions() as $option => $value) {
            $config->set($option, $value);
        }

        return $config;
    }

    /**
     * The config files in `config/htmlpurifier/`, ready for a select field.
     *
     * @return array<string, string>
     */
    public static function configOptions(): array
    {
        $options = ['' => Craft::t('app', 'Default')];
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . self::CONFIG_DIR;

        if (is_dir($path)) {
            $files = FileHelper::findFiles($path, [
                'only' => ['*.json'],
                'recursive' => false,
            ]);

            foreach ($files as $file) {
                $filename = basename($file);

                if ($filename !== self::DEFAULT_CONFIG_FILE) {
                    $options[$filename] = $filename;
                }
            }
        }

        ksort($options);

        return $options;
    }

    /**
     * The same defaults Craft’s HTML fields use, so a Markdown field sanitizes
     * the way authors expect — including YouTube and Vimeo embeds.
     *
     * @return array<string, mixed>
     */
    public static function defaultOptions(): array
    {
        return [
            'Attr.AllowedFrameTargets' => ['_blank'],
            'Attr.EnableID' => true,
            'HTML.SafeIframe' => true,
            'URI.SafeIframeRegexp' => '%^(https?:)?//(www\.youtube(-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function options(?string $configFile): ?array
    {
        $file = $configFile ?: self::DEFAULT_CONFIG_FILE;
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . self::CONFIG_DIR . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            // Named config has gone missing — fall back to Default.json, then to our own defaults
            return $file !== self::DEFAULT_CONFIG_FILE ? self::options(null) : null;
        }

        $options = Json::decodeIfJson((string)file_get_contents($path));

        return is_array($options) ? $options : null;
    }
}
