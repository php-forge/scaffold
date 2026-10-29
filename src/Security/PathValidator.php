<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Security;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use RuntimeException;

/**
 * Rejects source and destination paths that are absolute, contain `..` segments, escape their root through a symlink,
 * or address the reserved `scaffold-lock.json`.
 */
final class PathValidator
{
    /**
     * Maximum number of symlink hops followed when resolving a dangling symlink leaf, as the `ELOOP` limit of Linux.
     */
    private const int MAX_SYMLINK_HOPS = 40;

    /**
     * Destination reserved for the lock file written by the plugin.
     */
    private const string RESERVED_DESTINATION = 'scaffold-lock.json';

    /**
     * Returns whether `$path` contains a `..` segment, splitting on forward slashes and backslashes.
     *
     * @param string $path Path to check.
     *
     * @return bool `true` when a segment is `..`, `false` otherwise.
     */
    public static function hasTraversal(string $path): bool
    {
        return in_array('..', explode('/', str_replace('\\', '/', $path)), true);
    }

    /**
     * Returns whether `$path` starts with a directory separator or a Windows drive letter.
     *
     * @param string $path Path to check.
     *
     * @return bool `true` when the path is absolute or drive-qualified, `false` otherwise.
     */
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path) === 1;
    }

    /**
     * Validates that a destination path does not escape the project root.
     *
     * @param string $destination Relative destination path declared in the manifest.
     * @param string $projectRoot Absolute path to the project root directory.
     *
     * @throws RuntimeException when the project root does not exist, or the destination is absolute, contains `..`,
     * escapes the root through a symlink, or is reserved.
     *
     * @return string Canonical absolute path of the project root.
     */
    public function validateDestination(string $destination, string $projectRoot): string
    {
        $this->assertRelative($destination, 'destination');
        $this->assertNoTraversal($destination, 'destination');
        $this->assertNotReserved($destination);

        $realRoot = realpath($projectRoot);

        if ($realRoot === false) {
            throw new RuntimeException(
                Message::PATH_PROJECT_ROOT_INACCESSIBLE->getMessage($projectRoot),
            );
        }

        // A relative path without '..' segments stays under $realRoot; only symlinks can make it escape.
        $normalized = $this->normalizePath($realRoot, $destination);

        $this->assertNoSymlinkEscape($normalized, $realRoot, 'destination', $destination);

        return $realRoot;
    }

    /**
     * Validates that a source path does not escape the provider root.
     *
     * @param string $source Relative source path declared in the manifest.
     * @param string $providerRoot Absolute path to the provider root inside vendor.
     *
     * @throws RuntimeException when the provider root does not exist, or the source is absolute, contains `..` or
     * escapes the root through a symlink.
     *
     * @return string Canonical absolute path of the provider root.
     */
    public function validateSource(string $source, string $providerRoot): string
    {
        $this->assertRelative($source, 'source');
        $this->assertNoTraversal($source, 'source');

        $realRoot = realpath($providerRoot);

        if ($realRoot === false) {
            throw new RuntimeException(
                Message::PATH_PROVIDER_ROOT_INACCESSIBLE->getMessage($providerRoot),
            );
        }

        // A relative path without '..' segments stays under $realRoot; only symlinks can make it escape.
        $normalized = $this->normalizePath($realRoot, $source);

        $this->assertNoSymlinkEscape($normalized, $realRoot, 'source', $source);

        return $realRoot;
    }

    /**
     * Rejects `$normalized` when it starts a symlink chain that exceeds `MAX_SYMLINK_HOPS` hops, or in which a target
     * parent directory cannot be resolved or lies outside `$realRoot`.
     *
     * @param string $normalized Absolute, traversal-free path whose `realpath()` failed.
     * @param string $realRoot Resolved root boundary (output of `realpath()`).
     * @param string $context Human-readable label for error messages (`'source'` or `'destination'`).
     * @param string $originalPath Original user-supplied path, used only in the exception message.
     *
     * @throws RuntimeException when the symlink target escapes `$realRoot` or cannot be resolved.
     */
    private function assertDanglingSymlinkContained(
        string $normalized,
        string $realRoot,
        string $context,
        string $originalPath,
    ): void {
        $current = $normalized;
        $hops = 0;

        while (is_link($current)) {
            $target = Filesystem::readLink(
                $current,
                Message::PATH_SYMLINK_UNRESOLVABLE->getMessage(ucfirst($context), $originalPath),
            );

            if (++$hops > self::MAX_SYMLINK_HOPS) {
                throw new RuntimeException(
                    Message::PATH_SYMLINK_UNRESOLVABLE->getMessage(ucfirst($context), $originalPath),
                );
            }

            if (!self::isAbsolute($target)) {
                $target = dirname($current) . DIRECTORY_SEPARATOR . $target;
            }

            $parent = realpath(dirname($target));

            if ($parent === false) {
                throw new RuntimeException(
                    Message::PATH_SYMLINK_UNRESOLVABLE->getMessage(ucfirst($context), $originalPath),
                );
            }

            if (!str_starts_with($parent . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException(
                    Message::PATH_SYMLINK_ESCAPE->getMessage(ucfirst($context), $originalPath),
                );
            }

            $current = $parent . DIRECTORY_SEPARATOR . basename($target);
        }
    }

    /**
     * Rejects `$normalized` when the path itself, the target of a dangling symlink leaf, or any existing ancestor
     * resolves outside `$realRoot`.
     *
     * @param string $normalized Absolute, traversal-free path to validate.
     * @param string $realRoot Resolved root boundary (output of `realpath()`).
     * @param string $context Human-readable label for error messages (`'source'` or `'destination'`).
     * @param string $originalPath Original user-supplied path, used only in the exception message.
     *
     * @throws RuntimeException when the path escapes `$realRoot` through a symlink or a symlink cannot be resolved.
     */
    private function assertNoSymlinkEscape(
        string $normalized,
        string $realRoot,
        string $context,
        string $originalPath,
    ): void {
        $resolved = realpath($normalized);

        if ($resolved !== false) {
            if (!str_starts_with($resolved . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException(
                    Message::PATH_SYMLINK_ESCAPE->getMessage(ucfirst($context), $originalPath),
                );
            }

            return;
        }

        $this->assertDanglingSymlinkContained($normalized, $realRoot, $context, $originalPath);

        $dir = dirname($normalized);

        while ($dir !== $realRoot && $dir !== dirname($dir)) {
            $resolved = realpath($dir);

            if (
                $resolved !== false
                && !str_starts_with($resolved . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)
            ) {
                throw new RuntimeException(
                    Message::PATH_SYMLINK_ESCAPE->getMessage(ucfirst($context), $originalPath),
                );
            }

            $dir = dirname($dir);
        }
    }

    /**
     * Asserts that no path segment is `..`, preventing directory traversal.
     *
     * @param string $path Path to validate.
     * @param string $context Contextual name for error messages.
     *
     * @throws RuntimeException when a traversal segment is detected.
     */
    private function assertNoTraversal(string $path, string $context): void
    {
        if (self::hasTraversal($path)) {
            throw new RuntimeException(
                Message::PATH_TRAVERSAL->getMessage($context, $path),
            );
        }
    }

    /**
     * Asserts that `$destination` addresses neither `scaffold-lock.json` nor a path below it, ignoring case so that
     * case-insensitive filesystems cannot bypass the check.
     *
     * @param string $destination Relative destination path declared in the manifest.
     *
     * @throws RuntimeException when the destination is reserved.
     */
    private function assertNotReserved(string $destination): void
    {
        foreach (explode('/', str_replace('\\', '/', $destination)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if (strcasecmp($segment, self::RESERVED_DESTINATION) === 0) {
                throw new RuntimeException(
                    Message::PATH_RESERVED->getMessage($destination),
                );
            }

            return;
        }
    }

    /**
     * Asserts that a path is relative (does not start with a directory separator or drive letter).
     *
     * @param string $path Path to check.
     * @param string $context Path kind named in the error message (`destination` or `source`).
     *
     * @throws RuntimeException when an absolute path is detected.
     */
    private function assertRelative(string $path, string $context): void
    {
        if (self::isAbsolute($path)) {
            throw new RuntimeException(
                Message::PATH_ABSOLUTE->getMessage($context, $path),
            );
        }
    }

    /**
     * Joins an absolute base and a relative path already checked for `..` segments, without touching the filesystem.
     *
     * @param string $base Absolute base path.
     * @param string $relative Relative path to combine with the base.
     *
     * @return string Absolute path without trailing separator.
     */
    private function normalizePath(string $base, string $relative): string
    {
        return rtrim($base . DIRECTORY_SEPARATOR . $relative, DIRECTORY_SEPARATOR);
    }
}
