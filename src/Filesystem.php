<?php

declare(strict_types=1);

namespace PHPForge\Scaffold;

use Closure;
use PHPForge\Scaffold\Exception\Message;
use RuntimeException;

use function chmod;
use function copy;
use function count;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function hash_file;
use function is_file;
use function mkdir;
use function readlink;
use function rename;
use function restore_error_handler;
use function rmdir;
use function scandir;
use function set_error_handler;
use function sprintf;
use function umask;
use function uniqid;
use function unlink;

/**
 * Runs native filesystem calls and turns the PHP warning of a failed call into a {@see RuntimeException}.
 */
final class Filesystem
{
    /**
     * Copies a file, overwriting the destination.
     *
     * @param string $source Absolute path to the source file.
     * @param string $destination Absolute path to the destination file.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the file cannot be copied.
     */
    public static function copy(string $source, string $destination, string $failure): void
    {
        self::call(static fn(): bool => copy($source, $destination), $failure);
    }

    /**
     * Copies the permission bits of a file, masked by the current `umask`, onto another file.
     *
     * @param string $source Absolute path to the file whose permissions are copied.
     * @param string $destination Absolute path to the file that receives the permissions.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the permissions cannot be read or set.
     */
    public static function copyPermissions(string $source, string $destination, string $failure): void
    {
        $permissions = self::call(static fn(): int|false => fileperms($source), $failure);

        self::call(static fn(): bool => chmod($destination, $permissions & 0o777 & ~umask()), $failure);
    }

    /**
     * Returns the hexadecimal SHA-256 hash of a file.
     *
     * @param string $path Absolute path to the file.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the file cannot be read.
     *
     * @return string Hexadecimal SHA-256 hash of the file content.
     */
    public static function hash(string $path, string $failure): string
    {
        return self::call(static fn(): string|false => hash_file('sha256', $path), $failure);
    }

    /**
     * Returns whether a directory holds no entry.
     *
     * @param string $directory Absolute path to the directory.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the directory cannot be read.
     *
     * @return bool `true` when the directory has no entry besides `.` and `..`, `false` otherwise.
     */
    public static function isEmptyDirectory(string $directory, string $failure): bool
    {
        return count(self::call(static fn(): array|false => scandir($directory), $failure)) === 2;
    }

    /**
     * Creates a directory and its missing parents.
     *
     * @param string $directory Absolute path to the directory.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the directory cannot be created.
     */
    public static function mkdir(string $directory, string $failure): void
    {
        self::call(static fn(): bool => mkdir($directory, 0o777, recursive: true), $failure);
    }

    /**
     * Returns the content of a file.
     *
     * @param string $path Absolute path to the file.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the file cannot be read.
     *
     * @return string Content of the file.
     */
    public static function read(string $path, string $failure): string
    {
        return self::call(static fn(): string|false => file_get_contents($path), $failure);
    }

    /**
     * Returns the target of a symbolic link.
     *
     * @param string $path Absolute path to the symbolic link.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the link cannot be read.
     *
     * @return string Target of the link, as stored in the link.
     */
    public static function readLink(string $path, string $failure): string
    {
        return self::call(static fn(): string|false => readlink($path), $failure);
    }

    /**
     * Deletes a file.
     *
     * @param string $path Absolute path to the file.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the file cannot be deleted.
     */
    public static function remove(string $path, string $failure): void
    {
        self::call(static fn(): bool => unlink($path), $failure);
    }

    /**
     * Removes an empty directory.
     *
     * @param string $directory Absolute path to the directory.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the directory cannot be removed.
     */
    public static function removeDirectory(string $directory, string $failure): void
    {
        self::call(static fn(): bool => rmdir($directory), $failure);
    }

    /**
     * Writes content to a file, creating or truncating it.
     *
     * @param string $path Absolute path to the file.
     * @param string $content Content to write.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the file cannot be written.
     */
    public static function write(string $path, string $content, string $failure): void
    {
        self::call(static fn(): int|false => file_put_contents($path, $content), $failure);
    }

    /**
     * Writes content to a temporary file next to `$path` and renames it over `$path`, removing the temporary file
     * when the rename fails.
     *
     * @param string $path Absolute path to the file.
     * @param string $content Content to write.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the temporary file cannot be written, renamed or removed.
     */
    public static function writeAtomic(string $path, string $content, string $failure): void
    {
        $temporary = sprintf('%s.%s.tmp', $path, uniqid('', true));

        try {
            self::call(static fn(): int|false => file_put_contents($temporary, $content, LOCK_EX), $failure);
            self::call(static fn(): bool => rename($temporary, $path), $failure);
        } finally {
            if (is_file($temporary)) {
                self::remove($temporary, $failure);
            }
        }
    }

    /**
     * Runs a native filesystem call and throws when it raises a PHP diagnostic or returns `false`.
     *
     * @template T
     *
     * @param Closure(): (T|false) $operation Native call to run.
     * @param string $failure Message of the exception thrown on failure, before the PHP warning.
     *
     * @throws RuntimeException when the call raises a PHP diagnostic or returns `false`.
     *
     * @return T Result of the call.
     */
    private static function call(Closure $operation, string $failure): mixed
    {
        set_error_handler(
            static function (int $level, string $message) use ($failure): never {
                throw new RuntimeException(
                    Message::FILESYSTEM_FAILED->getMessage($failure, $message),
                );
            },
        );

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return $result !== false ? $result : throw new RuntimeException($failure);
    }
}
