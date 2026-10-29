<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold\Lock;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use PHPForge\Scaffold\Tests\Support\TempDirectoryTrait;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

use function file_put_contents;

/**
 * Unit tests for {@see Hasher} file hash computation.
 */
#[Test]
#[Covers(Hasher::class)]
final class HasherTest
{
    use TempDirectoryTrait;

    #[DataSet(['hello', 'sha256:2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824'], 'content')]
    #[DataSet(['', 'sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'], 'empty file')]
    public function hashReturnsPrefixedSha256Digest(string $content, string $expected): void
    {
        file_put_contents(
            "{$this->tempDir}/file.txt",
            $content,
        );

        Assert::same(
            Hasher::hash("{$this->tempDir}/file.txt"),
            $expected,
            'Hash must be the prefixed SHA-256 digest.',
        );
    }

    /**
     * Ensures a missing file throws with the PHP warning.
     */
    public function throwRuntimeExceptionWhenFileIsMissing(): void
    {
        $file = "{$this->tempDir}/nonexistent.txt";

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    Message::LOCK_HASH_FAILED->getMessage($file),
                    "hash_file({$file}): Failed to open stream: No such file or directory",
                ),
            );

        Hasher::hash($file);
    }

    /**
     * Ensures a file without read permission throws with the PHP warning.
     */
    public function throwRuntimeExceptionWhenFileIsUnreadable(): void
    {
        $file = "{$this->tempDir}/unreadable.txt";

        file_put_contents(
            $file,
            'content',
        );

        $this->restrictFileAccess($file, 0o000);

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    Message::LOCK_HASH_FAILED->getMessage($file),
                    "hash_file({$file}): Failed to open stream: Permission denied",
                ),
            );

        Hasher::hash($file);
    }

    /**
     * Ensures a directory throws with the PHP warning.
     */
    public function throwRuntimeExceptionWhenPathIsADirectory(): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessageContaining(
                Message::LOCK_HASH_FAILED->getMessage($this->tempDir) . ' Reason: hash_file(): Read of ',
            );

        Hasher::hash($this->tempDir);
    }
}
