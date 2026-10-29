<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Services;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Merge\MergeStrategyKind;
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Scaffold\PathResolver;
use PHPForge\Scaffold\Services\{ApplyPreview, ApplyPreviewer};
use PHPForge\Scaffold\Tests\Provider\ApplyPreviewerProvider;
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

use function mkdir;

/**
 * Unit tests for {@see ApplyPreviewer} and {@see ApplyPreview} computing the would-be content per mode in memory.
 *
 * {@see ApplyPreviewerProvider} for test case data providers.
 *
 * @phpstan-import-type LockEntry from LockFile
 */
#[Test]
#[Covers(ApplyPreviewer::class)]
#[Covers(ApplyPreview::class)]
final class ApplyPreviewerTest
{
    use TempDirectoryTrait;

    #[DataProvider(ApplyPreviewerProvider::class . '::lineEndings')]
    public function detectsDifferencesInLineEndingsOnly(
        bool $exists,
        string $current,
        string $proposed,
        bool $changes,
        bool $lineEndingsOnly,
    ): void {
        $preview = new ApplyPreview($this->mapping('a.txt', FileMode::Replace), $exists, $current, $proposed);

        Assert::same(
            [$preview->changes(), $preview->differsOnlyInLineEndings()],
            [$changes, $lineEndingsOnly],
            "Flags 'changes' and 'differsOnlyInLineEndings' must match.",
        );
    }

    /**
     * @param string|null $current Destination content, or `null` when the destination is missing.
     * @param array<string, bool|string> $expected Expected preview values keyed by property.
     */
    #[DataProvider(ApplyPreviewerProvider::class . '::modes')]
    public function proposesTheContentOfTheMode(
        string $destination,
        FileMode $mode,
        MergeStrategyKind|null $strategy,
        string $stub,
        string|null $current,
        array $expected,
    ): void {
        $project = (new InspectionProject($this->tempDir))->stub('p/a', $destination, $stub);

        if ($current !== null) {
            $project->file($destination, $current);
        }

        $preview = (new ApplyPreviewer())->preview($this->mapping($destination, $mode, $strategy), $this->tempDir);

        Assert::same(
            [
                'exists' => $preview->exists,
                'current' => $preview->current,
                'proposed' => $preview->proposed,
                'changes' => $preview->changes(),
                'informational' => $preview->informational,
            ],
            $expected,
            'Preview of the mode must match.',
        );
    }

    /**
     * @param LockEntry|null $entry Lock entry of the destination, or `null` when untracked.
     */
    #[DataProvider(ApplyPreviewerProvider::class . '::userModified')]
    public function reportsUserModifiedReplaceDestinations(array|null $entry, bool $expected): void
    {
        (new InspectionProject($this->tempDir))->stub('p/a', 'a.txt', "stub\n")->file('a.txt', "mine\n");

        Assert::same(
            (new ApplyPreviewer())
                ->preview($this->mapping('a.txt', FileMode::Replace), $this->tempDir, $entry)
                ->userModified,
            $expected,
            "Flag 'userModified' must match the lock entry.",
        );
    }

    public function throwRuntimeExceptionWhenDestinationCannotBeRead(): void
    {
        (new InspectionProject($this->tempDir))->stub('p/a', 'a.txt', "stub\n");

        // A directory where the destination file is expected cannot be read as a file.
        mkdir("{$this->tempDir}/a.txt");

        Expect::exception(RuntimeException::class)
            ->withMessageContaining(
                Message::SERVICE_CURRENT_FILE_UNREADABLE->getMessage(PathResolver::destination($this->tempDir, 'a.txt'))
                . ' Reason: file_get_contents(',
            );

        (new ApplyPreviewer())
            ->preview($this->mapping('a.txt', FileMode::Replace), $this->tempDir);
    }

    public function throwRuntimeExceptionWhenStubCannotBeRead(): void
    {
        (new InspectionProject($this->tempDir))->stub('p/a', 'a.txt', "stub\n");

        $stub = PathResolver::source("{$this->tempDir}/vendor/p/a", 'stubs/a.txt');

        $this->restrictFileAccess($stub, 0o000);

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    Message::SERVICE_STUB_UNREADABLE->getMessage($stub),
                    "file_get_contents({$stub}): Failed to open stream: Permission denied",
                ),
            );

        (new ApplyPreviewer())
            ->preview($this->mapping('a.txt', FileMode::Replace), $this->tempDir);
    }

    public function throwRuntimeExceptionWhenStubIsMissing(): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::SERVICE_STUB_NOT_FOUND->getMessage(
                    PathResolver::source("{$this->tempDir}/vendor/p/a", 'stubs/a.txt'),
                ),
            );

        (new ApplyPreviewer())
            ->preview($this->mapping('a.txt', FileMode::Replace), $this->tempDir);
    }

    /**
     * Returns a mapping of `$destination` from the `p/a` provider stub.
     */
    private function mapping(
        string $destination,
        FileMode $mode,
        MergeStrategyKind|null $strategy = null,
    ): FileMapping {
        return new FileMapping(
            $destination,
            "stubs/{$destination}",
            $mode,
            'p/a',
            "{$this->tempDir}/vendor/p/a",
            $strategy,
        );
    }
}
