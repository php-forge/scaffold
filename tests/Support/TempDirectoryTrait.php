<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\{AfterTest, BeforeTest};

use function chmod;
use function dirname;
use function function_exists;
use function is_dir;
use function is_link;
use function mkdir;
use function posix_geteuid;
use function rmdir;
use function sprintf;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Provides a temporary directory that is created before each test and removed after.
 *
 * Testo runs {@see self::setUpTempDirectory()} and {@see self::tearDownTempDirectory()} through their lifecycle
 * attributes.
 *
 * Pure-PHP recursive cleanup: walks the tree leaf-first, unlinks files and symlinks, then `rmdir`s directories.
 *
 * On Windows `rmdir` handles directory symlinks cleanly when their target has already been removed; plain file symlinks
 * are `unlink`ed like regular files.
 */
trait TempDirectoryTrait
{
    /**
     * Path to the temporary directory for the current test.
     */
    protected string $tempDir = '';

    /**
     * Creates a symbolic link at `$link` whose target is in a directory that does not exist, so writes through the
     * link fail, skipping the current test on Windows, where creating symbolic links needs extra privileges.
     *
     * Call it from the test body: a skip thrown from a Testo hook aborts the test instead.
     *
     * @param string $link Absolute path of the link to create.
     */
    protected function createDanglingLink(string $link): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('Creating symbolic links needs extra privileges on Windows.');
        }

        $this->ensureTestDirectory(dirname($link));

        symlink("{$this->tempDir}/missing-directory/target", $link);
    }

    /**
     * Recursively creates `$path` if it does not exist, failing the current test with a descriptive exception when the
     * directory cannot be created.
     *
     * Intended for test seed helpers that materialize nested fixture paths and would otherwise swallow a silent `mkdir`
     * failure behind a less informative downstream I/O warning.
     *
     * @param string $path Absolute path of the directory to ensure.
     *
     * @throws RuntimeException if the directory cannot be created.
     */
    protected function ensureTestDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (mkdir($path, 0o777, recursive: true) === false && !is_dir($path)) {
            throw new RuntimeException(sprintf('Failed to create test directory "%s".', $path));
        }
    }

    /**
     * Sets the permission bits of the file at `$path`, skipping the current test on Windows and as root, where
     * permission bits do not deny access.
     *
     * Call it from the test body: a skip thrown from a Testo hook aborts the test instead.
     *
     * @param string $path Absolute path of the file whose permissions are restricted.
     * @param int $mode Permission bits to set, such as `0o000` (no access) or `0o444` (read-only).
     */
    protected function restrictFileAccess(string $path, int $mode): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            throw new SkipTest('Permission bits do not deny access on Windows or as root.');
        }

        chmod($path, $mode);
    }

    /**
     * Creates a unique temporary directory for the current test.
     *
     * The directory is created in the system's temp directory and is automatically removed after the test completes.
     *
     * @throws RuntimeException if the temporary directory cannot be created.
     */
    #[BeforeTest]
    protected function setUpTempDirectory(): void
    {
        $path = sys_get_temp_dir() . '/scaffold-test-' . uniqid('', true);

        if (mkdir($path, 0o777, recursive: true) === false) {
            throw new RuntimeException(sprintf('Could not create temp directory "%s".', $path));
        }

        $this->tempDir = $path;
    }

    /**
     * Recursively removes the temporary directory and all its contents.
     *
     * Uses a pure-PHP implementation to ensure consistent behavior across platforms, especially regarding symlinks.
     */
    #[AfterTest]
    protected function tearDownTempDirectory(): void
    {
        if ($this->tempDir === '' || !is_dir($this->tempDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            if ($entry->isDir()) {
                if (is_link($path)) {
                    // POSIX 'unlink()' removes any symlink type; Windows rejects directory symlinks under
                    // 'DeleteFile' and requires 'rmdir()' instead.
                    @unlink($path) || @rmdir($path);

                    continue;
                }

                @rmdir($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($this->tempDir);
    }
}
