<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Config;

use function getenv;
use function is_string;
use function preg_match;
use function rtrim;
use function str_starts_with;

/**
 * Resolves the Composer vendor directory of a project from `COMPOSER_VENDOR_DIR`, then `config.vendor-dir` in the
 * project manifest (`composer.json`, or the file named by `COMPOSER`), then `<project-root>/vendor`.
 */
final class VendorDirResolver
{
    /**
     * Returns the file name of the project manifest: the `COMPOSER` environment variable when set, otherwise
     * `composer.json`.
     *
     * @return string Manifest file name, relative to the project root unless the `COMPOSER` value is absolute.
     */
    public static function manifestName(): string
    {
        $env = getenv('COMPOSER');

        return is_string($env) && $env !== '' ? $env : 'composer.json';
    }

    /**
     * Returns the absolute path of the project manifest named by {@see self::manifestName()}.
     *
     * @param string $projectRoot Absolute path to the project root directory.
     *
     * @return string Absolute path of the project manifest.
     */
    public static function manifestPath(string $projectRoot): string
    {
        return self::absolutize(self::manifestName(), $projectRoot);
    }

    /**
     * Returns the absolute path to the vendor directory for the project rooted at `$projectRoot`.
     *
     * @param string $projectRoot Absolute path to the project root directory.
     * @param string|null $configured `config.vendor-dir` of the project manifest, or `null` when unset.
     *
     * @return string Absolute path to the resolved vendor directory.
     */
    public static function resolve(string $projectRoot, string|null $configured = null): string
    {
        $env = getenv('COMPOSER_VENDOR_DIR');

        if (is_string($env) && $env !== '') {
            return self::absolutize($env, $projectRoot);
        }

        if ($configured !== null) {
            return self::absolutize($configured, $projectRoot);
        }

        return rtrim($projectRoot, '/\\') . '/vendor';
    }

    /**
     * Returns `$path` as an absolute path without trailing separators, joining relative and drive-relative paths (such
     * as `C:vendor`) to `$projectRoot` and keeping the separator of a drive root (such as `C:\`).
     *
     * @param string $path Absolute or relative filesystem path.
     * @param string $projectRoot Absolute path used as the base for relative `$path` values.
     *
     * @return string Absolute path; a drive root keeps its trailing separator, every other path has it stripped.
     */
    private static function absolutize(string $path, string $projectRoot): string
    {
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;

        if ($isAbsolute) {
            // Preserve drive-root separator: "C:\" and "C:/" must remain 3 characters.
            if (preg_match('#^([A-Za-z]:)([/\\\\])$#', $path, $matches) === 1) {
                return "{$matches[1]}{$matches[2]}";
            }

            return rtrim($path, '/\\');
        }

        return rtrim($projectRoot, '/\\') . '/' . rtrim($path, '/\\');
    }
}
