<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use PHPForge\Scaffold\Tests\Support\{FileAssert, TempDirectoryTrait};
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Test;

use function chmod;
use function error_clear_last;
use function error_get_last;
use function file_put_contents;
use function fileperms;
use function glob;
use function hash;
use function mkdir;
use function preg_quote;
use function restore_error_handler;
use function set_error_handler;
use function symlink;
use function umask;

/**
 * Unit tests for {@see Filesystem} native calls and the PHP warnings they turn into exceptions.
 */
#[Test]
#[Covers(Filesystem::class)]
final class FilesystemTest
{
    use TempDirectoryTrait;

    /**
     * Failure message passed to every call under test.
     */
    private const string FAILURE = 'Operation failed.';

    /**
     * Ensures the copy holds the source content.
     */
    public function copyCopiesTheFile(): void
    {
        file_put_contents("{$this->tempDir}/source.txt", "content\n");

        Filesystem::copy("{$this->tempDir}/source.txt", "{$this->tempDir}/copy.txt", self::FAILURE);

        FileAssert::contentSame(
            "{$this->tempDir}/copy.txt",
            "content\n",
            'Copy must hold the source content.',
        );
    }

    /**
     * Ensures the source permission bits are applied masked by the umask.
     */
    public function copyPermissionsAppliesTheSourceBitsMaskedByTheUmask(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('POSIX permissions do not apply to NTFS.');
        }

        file_put_contents("{$this->tempDir}/source.sh", 'x');
        file_put_contents("{$this->tempDir}/copy.sh", 'x');
        chmod("{$this->tempDir}/source.sh", 0o765);
        chmod("{$this->tempDir}/copy.sh", 0o600);

        $umask = umask(0o022);

        try {
            Filesystem::copyPermissions("{$this->tempDir}/source.sh", "{$this->tempDir}/copy.sh", self::FAILURE);
        } finally {
            umask($umask);
        }

        Assert::same(
            fileperms("{$this->tempDir}/copy.sh") & 0o777,
            0o745,
            "Permissions must be '0765' masked by the '0022' umask.",
        );
    }

    /**
     * Ensures a failed call restores the previous error handler and never reaches it.
     */
    public function errorHandlerIsRestoredAndNeverReachedWhenTheCallFails(): void
    {
        $calls = 0;

        $handler = static function () use (&$calls): bool {
            $calls++;

            return true;
        };

        error_clear_last();
        set_error_handler($handler);

        try {
            try {
                Filesystem::read("{$this->tempDir}/missing.txt", self::FAILURE);
            } catch (RuntimeException) {
                // The failure itself is covered by the read tests.
            }

            $current = set_error_handler(null);

            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        Assert::same(
            $current,
            $handler,
            'The previous error handler must be restored after a failed call.',
        );
        Assert::same(
            $calls,
            0,
            'The PHP warning of a failed call must never reach the previous error handler.',
        );
        Assert::null(
            error_get_last(),
            'The PHP warning must be captured, not raised.',
        );
    }

    /**
     * Ensures a successful call restores the previous error handler.
     */
    public function errorHandlerIsRestoredWhenTheCallSucceeds(): void
    {
        file_put_contents("{$this->tempDir}/a.txt", 'a');

        $handler = static fn(): bool => true;

        set_error_handler($handler);

        try {
            Filesystem::read("{$this->tempDir}/a.txt", self::FAILURE);

            $current = set_error_handler(null);

            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        Assert::same(
            $current,
            $handler,
            'The previous error handler must be restored after a successful call.',
        );
    }

    /**
     * Ensures the hash is the hexadecimal SHA-256 of the content.
     */
    public function hashReturnsTheSha256OfTheContent(): void
    {
        file_put_contents("{$this->tempDir}/a.txt", "content\n");

        Assert::same(
            Filesystem::hash("{$this->tempDir}/a.txt", self::FAILURE),
            hash('sha256', "content\n"),
            'Hash must be the hexadecimal SHA-256 of the content.',
        );
    }

    /**
     * Ensures a directory is empty only when it holds no entry.
     */
    public function isEmptyDirectoryReportsWhetherTheDirectoryHoldsEntries(): void
    {
        mkdir("{$this->tempDir}/empty");
        mkdir("{$this->tempDir}/full");
        file_put_contents("{$this->tempDir}/full/a.txt", 'a');

        Assert::true(
            Filesystem::isEmptyDirectory("{$this->tempDir}/empty", self::FAILURE),
            'A directory without entries must be empty.',
        );
        Assert::false(
            Filesystem::isEmptyDirectory("{$this->tempDir}/full", self::FAILURE),
            'A directory with one entry must not be empty.',
        );
    }

    /**
     * Ensures every created directory gets the full permission bits masked by the umask.
     */
    public function mkdirAppliesFullPermissionsMaskedByTheUmask(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('POSIX permissions do not apply to NTFS.');
        }

        $umask = umask(0o022);

        try {
            Filesystem::mkdir("{$this->tempDir}/a/b", self::FAILURE);
        } finally {
            umask($umask);
        }

        Assert::same(
            [fileperms("{$this->tempDir}/a") & 0o777, fileperms("{$this->tempDir}/a/b") & 0o777],
            [0o755, 0o755],
            "Permissions must be '0777' masked by the '0022' umask.",
        );
    }

    /**
     * Ensures every missing parent directory is created.
     */
    public function mkdirCreatesMissingParents(): void
    {
        Filesystem::mkdir("{$this->tempDir}/a/b/c", self::FAILURE);

        FileAssert::directoryExists(
            "{$this->tempDir}/a/b/c",
            'Every missing parent must be created.',
        );
    }

    /**
     * Ensures the target of a symbolic link is returned as stored.
     */
    public function readLinkReturnsTheStoredTarget(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('Creating symbolic links needs extra privileges on Windows.');
        }

        symlink('target.txt', "{$this->tempDir}/link");

        Assert::same(
            Filesystem::readLink("{$this->tempDir}/link", self::FAILURE),
            'target.txt',
            'The relative target must be returned as stored.',
        );
    }

    /**
     * Ensures the file content is returned.
     */
    public function readReturnsTheContent(): void
    {
        file_put_contents("{$this->tempDir}/a.txt", "content\n");

        Assert::same(
            Filesystem::read("{$this->tempDir}/a.txt", self::FAILURE),
            "content\n",
            'Read must return the file content.',
        );
    }

    /**
     * Ensures the file is deleted.
     */
    public function removeDeletesTheFile(): void
    {
        file_put_contents("{$this->tempDir}/a.txt", 'a');

        Filesystem::remove("{$this->tempDir}/a.txt", self::FAILURE);

        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/a.txt",
            'The file must be deleted.',
        );
    }

    /**
     * Ensures an empty directory is removed.
     */
    public function removeDirectoryRemovesAnEmptyDirectory(): void
    {
        mkdir("{$this->tempDir}/empty");

        Filesystem::removeDirectory("{$this->tempDir}/empty", self::FAILURE);

        FileAssert::directoryDoesNotExist(
            "{$this->tempDir}/empty",
            'The empty directory must be removed.',
        );
    }

    /**
     * Ensures a missing permission source throws with the PHP warning as the reason.
     */
    public function throwRuntimeExceptionWhenCopyPermissionsSourceIsMissing(): void
    {
        file_put_contents("{$this->tempDir}/copy.sh", 'x');

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    self::FAILURE,
                    "fileperms(): stat failed for {$this->tempDir}/missing.sh",
                ),
            );

        Filesystem::copyPermissions("{$this->tempDir}/missing.sh", "{$this->tempDir}/copy.sh", self::FAILURE);
    }

    /**
     * Ensures a missing copy source throws with the PHP warning as the reason.
     */
    public function throwRuntimeExceptionWhenCopySourceIsMissing(): void
    {
        $source = "{$this->tempDir}/missing.txt";

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    self::FAILURE,
                    "copy({$source}): Failed to open stream: No such file or directory",
                ),
            );

        Filesystem::copy($source, "{$this->tempDir}/copy.txt", self::FAILURE);
    }

    /**
     * Ensures a missing hashed file throws with the PHP warning as the reason.
     */
    public function throwRuntimeExceptionWhenHashedFileIsMissing(): void
    {
        $path = "{$this->tempDir}/missing.txt";

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    self::FAILURE,
                    "hash_file({$path}): Failed to open stream: No such file or directory",
                ),
            );

        Filesystem::hash($path, self::FAILURE);
    }

    /**
     * Ensures listing a regular file as a directory throws with the PHP warning as the reason.
     */
    public function throwRuntimeExceptionWhenListedDirectoryIsARegularFile(): void
    {
        $path = "{$this->tempDir}/a.txt";

        file_put_contents($path, 'a');

        Expect::exception(RuntimeException::class)
            ->withMessageMatchingRegex(self::failurePattern("scandir({$path}): Failed to open directory: "));

        Filesystem::isEmptyDirectory($path, self::FAILURE);
    }

    /**
     * Ensures a parent path that is a regular file makes the directory creation throw.
     */
    public function throwRuntimeExceptionWhenMkdirParentIsARegularFile(): void
    {
        file_put_contents("{$this->tempDir}/blocker", 'x');

        Expect::exception(RuntimeException::class)
            ->withMessageMatchingRegex(self::failurePattern('mkdir(): '));

        Filesystem::mkdir("{$this->tempDir}/blocker/sub", self::FAILURE);
    }

    /**
     * Ensures a missing file throws on read with the PHP warning as the reason.
     */
    public function throwRuntimeExceptionWhenReadFileIsMissing(): void
    {
        $path = "{$this->tempDir}/missing.txt";

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    self::FAILURE,
                    "file_get_contents({$path}): Failed to open stream: No such file or directory",
                ),
            );

        Filesystem::read($path, self::FAILURE);
    }

    /**
     * Ensures reading a regular file as a symbolic link throws.
     */
    public function throwRuntimeExceptionWhenReadLinkTargetIsNotALink(): void
    {
        file_put_contents("{$this->tempDir}/a.txt", 'a');

        Expect::exception(RuntimeException::class)
            ->withMessageMatchingRegex(self::failurePattern('readlink(): '));

        Filesystem::readLink("{$this->tempDir}/a.txt", self::FAILURE);
    }

    /**
     * Ensures reading a directory as a file throws.
     */
    public function throwRuntimeExceptionWhenReadPathIsADirectory(): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessageContaining(self::FAILURE . ' Reason: file_get_contents(');

        // A directory reads as an empty string with a PHP notice, which counts as a failure.
        Filesystem::read($this->tempDir, self::FAILURE);
    }

    /**
     * Ensures removing a non-empty directory throws.
     */
    public function throwRuntimeExceptionWhenRemovedDirectoryIsNotEmpty(): void
    {
        mkdir("{$this->tempDir}/full");
        file_put_contents("{$this->tempDir}/full/a.txt", 'a');

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    self::FAILURE,
                    "rmdir({$this->tempDir}/full): Directory not empty",
                ),
            );

        Filesystem::removeDirectory("{$this->tempDir}/full", self::FAILURE);
    }

    /**
     * Ensures removing a directory as a file throws.
     */
    public function throwRuntimeExceptionWhenRemovedFileIsADirectory(): void
    {
        mkdir("{$this->tempDir}/dir");

        Expect::exception(RuntimeException::class)
            ->withMessageMatchingRegex(self::failurePattern("unlink({$this->tempDir}/dir): "));

        Filesystem::remove("{$this->tempDir}/dir", self::FAILURE);
    }

    /**
     * Ensures writing to a directory as a file throws.
     */
    public function throwRuntimeExceptionWhenWrittenPathIsADirectory(): void
    {
        mkdir("{$this->tempDir}/dir");

        Expect::exception(RuntimeException::class)
            ->withMessageMatchingRegex(self::failurePattern("file_put_contents({$this->tempDir}/dir): Failed to open stream: "));

        Filesystem::write("{$this->tempDir}/dir", 'x', self::FAILURE);
    }

    /**
     * Ensures an atomic write replaces the file and leaves no temporary file.
     */
    public function writeAtomicReplacesTheFileWithoutLeavingTemporaryFiles(): void
    {
        file_put_contents("{$this->tempDir}/a.json", 'old');

        Filesystem::writeAtomic("{$this->tempDir}/a.json", 'new', self::FAILURE);

        FileAssert::contentSame(
            "{$this->tempDir}/a.json",
            'new',
            'The file must hold the new content.',
        );
        Assert::same(
            glob("{$this->tempDir}/a.json*"),
            ["{$this->tempDir}/a.json"],
            'No temporary file may remain after a successful write.',
        );
    }

    /**
     * Ensures a write leaves only the new content in the file.
     */
    public function writeCreatesOrTruncatesTheFile(): void
    {
        file_put_contents("{$this->tempDir}/a.txt", 'longer old content');

        Filesystem::write("{$this->tempDir}/a.txt", 'new', self::FAILURE);

        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            'new',
            'The file must hold only the new content.',
        );
    }

    /**
     * Returns the pattern of a failure message whose reason starts with `$reasonPrefix` and ends with an error text that
     * depends on the platform.
     *
     * @param string $reasonPrefix Start of the PHP warning, up to the error text.
     *
     * @return non-empty-string Regular expression that matches the whole message.
     */
    private static function failurePattern(string $reasonPrefix): string
    {
        return '/\A' . preg_quote(self::FAILURE . " Reason: {$reasonPrefix}", '/') . '.+\.\z/';
    }
}
