<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold;

use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use PHPForge\Scaffold\Security\PathValidator;
use RuntimeException;

use function dirname;
use function is_array;
use function is_dir;
use function is_string;
use function ltrim;
use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function stripos;

/**
 * Resolves absolute scaffold paths from roots and relative segments that callers have already validated with
 * {@see \PHPForge\Scaffold\Security\PathValidator}.
 */
final class PathResolver
{
    /**
     * Joins a project root with a relative destination path using the platform separator.
     *
     * @param string $projectRoot Absolute path to the project root directory.
     * @param string $destination Relative path to a destination file or directory inside the project, using forward
     * slashes as separators.
     *
     * @return string Absolute path to the destination file or directory inside the project, with platform separators.
     */
    public static function destination(string $projectRoot, string $destination): string
    {
        return rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($destination, '/\\');
    }

    /**
     * Creates the parent directory for the given absolute file path when it does not exist.
     *
     * @param string $absoluteFilePath Absolute path to the file whose parent directory is created.
     *
     * @throws RuntimeException when the directory cannot be created.
     */
    public static function ensureDirectory(string $absoluteFilePath): void
    {
        $dir = dirname($absoluteFilePath);

        if (!is_dir($dir)) {
            Filesystem::mkdir($dir, Message::PATH_DIRECTORY_CREATE_FAILED->getMessage($dir));
        }
    }

    /**
     * Returns the canonical absolute path of `$path`, falling back to a trimmed copy when `realpath()` fails.
     *
     * @param string $path Absolute or relative path to resolve.
     *
     * @return string Canonical absolute path or trimmed fallback.
     */
    public static function realpathOrFallback(string $path): string
    {
        $resolved = realpath($path);

        return $resolved !== false ? $resolved : rtrim($path, '/\\');
    }

    /**
     * Resolves the absolute root of a provider from its lock-recorded path when that path, relative or absolute, stays
     * inside the vendor directory, falling back to `<vendorDir>/<providerName>`.
     *
     * @param string $vendorDir Absolute path to the Composer vendor directory.
     * @param string $providerName Composer package name of the provider, used for the fallback root.
     * @param mixed $providerLock Decoded `providers.<name>` entry of `scaffold-lock.json`, or `null` when absent.
     * @param string $projectRoot Absolute path to the project root that relative recorded paths are joined to; an empty
     * string leaves them unexpanded.
     *
     * @return array{root: string, warning: string|null} Provider root, and a warning when the recorded path resolves
     * outside the vendor directory.
     */
    public static function resolveProviderRoot(
        string $vendorDir,
        string $providerName,
        mixed $providerLock,
        string $projectRoot = '',
    ): array {
        $safeVendorDir = self::realpathOrFallback($vendorDir);

        $defaultRoot = $safeVendorDir . DIRECTORY_SEPARATOR . $providerName;

        if (!is_array($providerLock) || !is_string($providerLock['path'] ?? null)) {
            return [
                'root' => $defaultRoot,
                'warning' => null,
            ];
        }

        $recordedPath = $providerLock['path'];

        $expanded = PathValidator::isAbsolute($recordedPath) || $projectRoot === ''
            ? $recordedPath
            : rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . $recordedPath;

        $candidate = self::realpathOrFallback($expanded);

        if (self::isWithin($candidate, $safeVendorDir, DIRECTORY_SEPARATOR === '\\')) {
            return [
                'root' => $candidate,
                'warning' => null,
            ];
        }

        return [
            'root' => $defaultRoot,
            'warning' => ConsoleMessage::SCAFFOLD_PROVIDER_ROOT_OUTSIDE_VENDOR->getMessage($providerName),
        ];
    }

    /**
     * Joins a provider root with a relative source path.
     *
     * @param string $providerPath Absolute path to the provider root directory.
     * @param string $source Relative path to a source file or directory inside the provider, using forward slashes as
     * separators.
     *
     * @return string Absolute path to the source file or directory inside the provider.
     */
    public static function source(string $providerPath, string $source): string
    {
        return rtrim($providerPath, '/\\') . DIRECTORY_SEPARATOR . ltrim($source, '/\\');
    }

    /**
     * Copies the source permission bits, masked by the current `umask`, onto the destination so executable stubs stay
     * executable, and reports a failure instead of raising a PHP warning.
     *
     * @param string $source Absolute path to the source file.
     * @param string $destination Absolute path to the destination file.
     *
     * @return string|null Warning without the `[scaffold]` prefix when the permissions could not be read or set, or
     * `null` on success.
     */
    public static function syncPermissions(string $source, string $destination): string|null
    {
        try {
            Filesystem::copyPermissions(
                $source,
                $destination,
                ConsoleMessage::SCAFFOLD_PERMISSIONS_COPY_FAILED->getMessage($source, $destination),
            );
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Returns whether `$path` is `$directory` or lies inside it, treating `/` and `\` as the same separator and
     * ignoring case when `$caseInsensitive` is `true`.
     *
     * A path that `realpath()` could not resolve keeps the separators it was joined with, so on Windows it can mix both.
     *
     * @param string $path Absolute path to check.
     * @param string $directory Absolute path of the containing directory.
     * @param bool $caseInsensitive Whether to compare ignoring case, as Windows filesystems do.
     *
     * @return bool `true` when `$path` is `$directory` or one of its descendants, `false` otherwise.
     */
    private static function isWithin(string $path, string $directory, bool $caseInsensitive): bool
    {
        $normalizedPath = str_replace('\\', '/', $path) . '/';
        $normalizedDirectory = str_replace('\\', '/', $directory) . '/';

        return $caseInsensitive
            ? stripos($normalizedPath, $normalizedDirectory) === 0
            : str_starts_with($normalizedPath, $normalizedDirectory);
    }
}
