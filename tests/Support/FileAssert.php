<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use Testo\Assert;

use function file_exists;
use function file_get_contents;
use function is_dir;
use function is_file;

/**
 * Provides the file and directory assertions that {@see Assert} lacks, each recorded as one Testo assertion.
 */
final class FileAssert
{
    /**
     * Asserts that `$path` is a regular file whose content is identical to `$expected`.
     *
     * A missing file is compared as `null`, so the failure shows the expected content without a PHP warning.
     *
     * @param string $path Absolute path of the file.
     * @param string $expected Expected content.
     * @param string $message Failure message.
     */
    public static function contentSame(string $path, string $expected, string $message): void
    {
        Assert::same(
            is_file($path) ? file_get_contents($path) : null,
            $expected,
            $message,
        );
    }

    /**
     * Asserts that `$path` is not a directory.
     *
     * @param string $path Absolute path.
     * @param string $message Failure message.
     */
    public static function directoryDoesNotExist(string $path, string $message): void
    {
        Assert::false(is_dir($path), $message);
    }

    /**
     * Asserts that `$path` is a directory.
     *
     * @param string $path Absolute path.
     * @param string $message Failure message.
     */
    public static function directoryExists(string $path, string $message): void
    {
        Assert::true(is_dir($path), $message);
    }

    /**
     * Asserts that nothing exists at `$path`; a symbolic link to a missing target counts as missing.
     *
     * @param string $path Absolute path.
     * @param string $message Failure message.
     */
    public static function fileDoesNotExist(string $path, string $message): void
    {
        Assert::false(file_exists($path), $message);
    }

    /**
     * Asserts that `$path` is a regular file, or a symbolic link to one.
     *
     * @param string $path Absolute path.
     * @param string $message Failure message.
     */
    public static function fileExists(string $path, string $message): void
    {
        Assert::true(is_file($path), $message);
    }
}
