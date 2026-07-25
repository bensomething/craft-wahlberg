<?php

namespace bensomething\wahlberg\tests\support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Directory handling for the harness itself.
 *
 * Craft's FileHelper reads its file and directory modes off the general config, which
 * these tests deliberately don't have, so the scaffolding does its own.
 */
class Dir
{
    public static function create(string $path): string
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    public static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $contents = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($contents as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
