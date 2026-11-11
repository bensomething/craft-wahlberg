<?php

namespace bensomething\wahlberg\helpers;

use craft\helpers\Markdown as CraftMarkdown;
use CraftCms\Cms\Support\Facades\Markdown as NativeMarkdown;

/**
 * Parses Markdown with whichever parser the running Craft version provides.
 *
 * On Craft 6 this uses Craft's native (CommonMark-based) parser. The Yii2
 * adapter's legacy `craft\helpers\Markdown` can't be used there: its
 * `getParser()` is typed to return `craft\markdown\Markdown`, so every flavour
 * other than `original` throws a TypeError. On Craft 5 it uses Craft's helper
 * as before. Both understand the same flavour names (`gfm`, `gfm-comment`,
 * `original`, `extra`) and both strip unsafe links.
 */
final class MarkdownParser
{
    public static function process(string $markdown, ?string $flavour = null): string
    {
        if (self::isNative()) {
            // @phpstan-ignore-next-line Craft 6 only, guarded by isNative()
            return NativeMarkdown::parse($markdown, $flavour);
        }

        return CraftMarkdown::process($markdown, $flavour);
    }

    public static function processParagraph(string $markdown, ?string $flavour = null): string
    {
        if (self::isNative()) {
            // @phpstan-ignore-next-line Craft 6 only, guarded by isNative()
            return NativeMarkdown::parseParagraph($markdown, $flavour);
        }

        return CraftMarkdown::processParagraph($markdown, $flavour);
    }

    private static function isNative(): bool
    {
        return class_exists(NativeMarkdown::class);
    }
}
