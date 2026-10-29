<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold\Lock;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Tests\Provider\LockFileProvider;
use PHPForge\Scaffold\Tests\Support\{FileAssert, TempDirectoryTrait};
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\{DataProvider, DataSet};
use Testo\Expect;
use Testo\Test;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function mkdir;
use function preg_match;
use function preg_quote;
use function str_contains;
use function str_replace;
use function unlink;

/**
 * Unit tests for {@see LockFile} read/write operations, deterministic formatting, and error reporting.
 *
 * {@see LockFileProvider} for test case data providers.
 */
#[Test]
#[Covers(LockFile::class)]
final class LockFileTest
{
    use TempDirectoryTrait;

    public function existsOnlyAfterWrite(): void
    {
        $lock = new LockFile($this->tempDir);

        $before = $lock->exists();

        $lock->write(['providers' => [], 'files' => []]);

        Assert::same(
            [$before, $lock->exists()],
            [false, true],
            "Lock must exist only after 'write()'.",
        );
    }

    public function getPathJoinsProjectRootWithOneSeparator(): void
    {
        Assert::same(
            [(new LockFile($this->tempDir))->getPath(), (new LockFile('/tmp/project/'))->getPath()],
            [
                $this->tempDir . DIRECTORY_SEPARATOR . 'scaffold-lock.json',
                '/tmp/project' . DIRECTORY_SEPARATOR . 'scaffold-lock.json',
            ],
            'Lock path must hold one separator before the file name.',
        );
    }

    public function readCachesResultAfterFirstCall(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(
            [
                'providers' => [],
                'files' => [
                    'a.txt' => ['hash' => 'sha256:abc', 'provider' => 'pkg/a', 'source' => 'stubs/a.txt', 'mode' => 'replace'],
                ],
            ],
        );

        $first = $lock->read();

        unlink($lock->getPath());

        Assert::same(
            $lock->read(),
            $first,
            'Cached data must be returned without reading the file again.',
        );
    }

    public function readPreservesIntegerLikeDestinationsAfterWrite(): void
    {
        $entry = [
            'hash' => 'sha256:abc',
            'provider' => 'pkg/name',
            'source' => '0',
            'mode' => 'replace',
        ];

        $lock = new LockFile($this->tempDir);

        $lock->write(['providers' => [], 'files' => [0 => $entry, 1 => $entry]]);

        Assert::true(
            str_contains((string) file_get_contents($lock->getPath()), '"0": {'),
            "Integer-like destination '0' must stay a JSON object key.",
        );
        Assert::same(
            $lock->read()['files']['1'] ?? null,
            $entry,
            'Entry must be readable by its string name.',
        );
    }

    public function readReturnsEmptyStructureWhenFileIsMissing(): void
    {
        Assert::same(
            (new LockFile($this->tempDir))->read(),
            ['providers' => [], 'files' => []],
            'Missing lock must read as empty providers and files.',
        );
    }

    public function readReturnsWhatWasWritten(): void
    {
        $data = [
            'providers' => ['pkg/name' => ['path' => '/vendor/pkg/name']],
            'files' => [
                'config/params.php' => ['hash' => 'sha256:abc', 'provider' => 'pkg/name', 'mode' => 'preserve'],
            ],
        ];

        (new LockFile($this->tempDir))->write($data);

        Assert::same(
            (new LockFile($this->tempDir))->read(),
            $data,
            'Lock data must survive a write/read round trip.',
        );
    }

    public function throwRuntimeExceptionWhenDataCannotBeEncoded(): void
    {
        $lock = new LockFile($this->tempDir);

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::LOCK_ENCODE_FAILED->getMessage(
                    $lock->getPath(),
                    'Malformed UTF-8 characters, possibly incorrectly encoded',
                ),
            )
            ->withCode(0);

        $lock->write(['providers' => ["bad\xB1name" => []], 'files' => []]);
    }

    #[DataProvider(LockFileProvider::class . '::invalidContents')]
    public function throwRuntimeExceptionWhenLockContentIsInvalid(string $content, string $message): void
    {
        $lock = new LockFile($this->tempDir);

        file_put_contents(
            $lock->getPath(),
            $content,
        );

        Expect::exception(RuntimeException::class)
            ->withMessage(
                str_replace('{path}', $lock->getPath(), $message),
            )
            ->withCode(0);

        $lock->read();
    }

    public function throwRuntimeExceptionWhenLockFileIsUnreadable(): void
    {
        $lock = new LockFile($this->tempDir);

        $path = $lock->getPath();

        file_put_contents(
            $path,
            '{"providers":{},"files":{}}',
        );

        $this->restrictFileAccess($path, 0o000);

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    Message::LOCK_UNREADABLE->getMessage($path),
                    "file_get_contents({$path}): Failed to open stream: Permission denied",
                ),
            );

        $lock->read();
    }

    public function throwRuntimeExceptionWhenRenameFails(): void
    {
        $lock = new LockFile($this->tempDir);

        $path = $lock->getPath();

        // A non-empty directory at the lock path makes the rename of the temporary file fail.
        mkdir("{$path}/entry", 0o777, true);

        $message = null;

        try {
            $lock->write(['providers' => [], 'files' => []]);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        Assert::same(
            preg_match(
                '/^' . preg_quote(Message::LOCK_UNWRITABLE->getMessage($path) . ' Reason: rename(', '/')
                . '.+\.$/',
                (string) $message,
            ),
            1,
            "Failed 'rename()' must surface with the lock path and its reason.",
        );
        Assert::same(
            glob("{$path}*"),
            [$path],
            'No temporary file may remain after a failed rename.',
        );
    }

    public function throwRuntimeExceptionWhenWritingTemporaryFileFails(): void
    {
        $lock = new LockFile("{$this->tempDir}/missing");

        Expect::exception(RuntimeException::class)
            ->withMessageMatchingRegex(
                '/^' . preg_quote(
                    Message::LOCK_UNWRITABLE->getMessage($lock->getPath()) . ' Reason: file_put_contents(',
                    '/',
                )
                . '.+\): Failed to open stream: No such file or directory\.$/',
            );

        $lock->write(['providers' => [], 'files' => []]);
    }

    public function writeEncodesEmptyMapsAsObjects(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(['providers' => [], 'files' => []]);

        FileAssert::contentSame(
            $lock->getPath(),
            "{\n    \"providers\": {},\n    \"files\": {}\n}\n",
            "Empty maps must be '{}' and the file must end with one newline.",
        );
    }

    public function writePreservesUnknownEntryKeysOfRewrittenEntries(): void
    {
        $lock = new LockFile($this->tempDir);

        file_put_contents(
            $lock->getPath(),
            '{"providers": {}, "files": {'
            . '"a.txt": {"hash": "sha256:old", "provider": "p/a", "source": "a.txt", "mode": "replace", "ejected": true,'
            . ' "future": [1]},'
            . '"b.txt": {"hash": "sha256:b", "provider": "p/a", "source": "b.txt", "mode": "replace", "other": true}'
            . '}}',
        );

        $lock->read();
        $lock->write(
            [
                'providers' => [],
                'files' => [
                    'a.txt' => [
                        'hash' => 'sha256:new',
                        'provider' => 'p/a',
                        'source' => 'a.txt',
                        'mode' => 'replace',
                        'stub-hash' => 'sha256:stub',
                        'adopted' => true,
                    ],
                ],
            ],
        );

        Assert::same(
            $lock->read()['files'],
            [
                'a.txt' => [
                    'hash' => 'sha256:new',
                    'provider' => 'p/a',
                    'source' => 'a.txt',
                    'mode' => 'replace',
                    'stub-hash' => 'sha256:stub',
                    'adopted' => true,
                    'future' => [1],
                ],
            ],
            'Only unknown entry keys may survive the rewrite.',
        );
    }

    #[DataSet([true], 'after read')]
    #[DataSet([false], 'without prior read')]
    public function writePreservesUnknownTopLevelKeys(bool $readFirst): void
    {
        $lock = new LockFile($this->tempDir);

        file_put_contents(
            $lock->getPath(),
            '{"version": 2, "providers": {}, "files": {}, "ejected": ["a.txt"]}',
        );

        $lock->write($readFirst ? $lock->read() : ['providers' => [], 'files' => []]);

        FileAssert::contentSame(
            $lock->getPath(),
            "{\n    \"providers\": {},\n    \"files\": {},\n    \"version\": 2,\n    \"ejected\": [\n"
            . "        \"a.txt\"\n    ]\n}\n",
            'Unknown top-level keys must survive a rewrite.',
        );
    }

    public function writeSortsKeysInByteOrder(): void
    {
        $entry = [
            'hash' => 'h',
            'provider' => 'p/b',
            'source' => 's',
            'mode' => 'replace',
        ];

        $lock = new LockFile($this->tempDir);

        $lock->write(
            [
                'providers' => [
                    'p/b' => ['version' => '1.0.0', 'path' => 'vendor/p/b'],
                    'p/a' => ['version' => '2.0.0', 'path' => 'vendor/p/a'],
                ],
                'files' => ['b.txt' => $entry, 'B.txt' => $entry, '.a' => $entry, '10' => $entry, '9' => $entry],
            ],
        );

        $expectedEntry = "{\n            \"hash\": \"h\",\n            \"provider\": \"p/b\",\n"
            . "            \"source\": \"s\",\n            \"mode\": \"replace\"\n        }";

        FileAssert::contentSame(
            $lock->getPath(),
            "{\n    \"providers\": {\n"
            . "        \"p/a\": {\n            \"version\": \"2.0.0\",\n"
            . "            \"path\": \"vendor/p/a\"\n        },\n"
            . "        \"p/b\": {\n            \"version\": \"1.0.0\",\n"
            . "            \"path\": \"vendor/p/b\"\n        }\n"
            . "    },\n"
            . "    \"files\": {\n"
            . "        \".a\": {$expectedEntry},\n"
            . "        \"10\": {$expectedEntry},\n"
            . "        \"9\": {$expectedEntry},\n"
            . "        \"B.txt\": {$expectedEntry},\n"
            . "        \"b.txt\": {$expectedEntry}\n"
            . "    }\n"
            . "}\n",
            'Keys must be sorted in byte order, independent of insertion order.',
        );
    }
}
