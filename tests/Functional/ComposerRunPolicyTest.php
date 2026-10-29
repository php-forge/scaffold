<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Functional;

use Composer\IO\BufferIO;
use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\EventSubscriber;
use PHPForge\Scaffold\Exception\{Message, ScaffoldException};
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Tests\Support\{
    ComposerEventHarness,
    ExpectedSummary,
    FakeProjectBuilder,
    FileAssert,
    TempDirectoryTrait,
};
use RuntimeException;
use Testo\Assert;
use Testo\Test;

use function array_keys;
use function file_put_contents;
use function get_debug_type;
use function realpath;

/**
 * Functional tests for the configuration notices, failures, orphans and conflicts that {@see EventSubscriber} reports
 * during a Composer run.
 */
#[Test]
final class ComposerRunPolicyTest
{
    use ComposerEventHarness;
    use TempDirectoryTrait;

    public function conflictIsReportedUntilTheProviderUpdateIsTaken(): void
    {
        $builder = $this->project();

        $builder->createStubFile('demo/scaffold', 'a.txt', "v1\n");
        $builder->createStubFile('demo/scaffold', 'keep.txt', "keep v1\n");

        $manifest = ['copy' => ['a.txt', 'keep.txt'], 'modes' => ['keep.txt' => 'preserve']];

        $this->dispatch($builder, $manifest);

        file_put_contents(
            "{$builder->getProjectRoot()}/a.txt",
            "mine\n",
        );

        $skipped = ConsoleMessage::SCAFFOLD_PREFIXED->getMessage(
            ConsoleMessage::SCAFFOLD_USER_MODIFIED_SKIPPED->getMessage('a.txt'),
        ) . PHP_EOL;

        Assert::same(
            $this->dispatch($builder, $manifest),
            $skipped
            . ExpectedSummary::line(0, 1, ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_WARNING))
            . PHP_EOL,
            'A user edit without a provider change is no conflict.',
        );

        $builder->createStubFile('demo/scaffold', 'a.txt', "v2\n");
        $builder->createStubFile('demo/scaffold', 'keep.txt', "keep v2\n");

        $conflict = $skipped
            . ConsoleMessage::SCAFFOLD_CONFLICTS->getMessage(1, ConsoleMessage::FRAGMENT_CONFLICT_FILE->getMessage())
            . PHP_EOL;

        Assert::same(
            $this->dispatch($builder, $manifest),
            $conflict
            . ExpectedSummary::line(
                0,
                1,
                ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_OUTDATED),
                ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_CONFLICT),
                ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_WARNING),
            )
            . PHP_EOL,
            'Bump must report the conflict and the kept preserve file.',
        );
        Assert::same(
            $this->dispatch($builder, $manifest),
            $conflict
            . ExpectedSummary::line(
                0,
                1,
                ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_CONFLICT),
                ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_WARNING),
            )
            . PHP_EOL,
            'Conflict must persist until the update is taken.',
        );
        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/a.txt",
            "mine\n",
            'Edit must survive.',
        );
        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/keep.txt",
            "keep v1\n",
            'Preserve file must stay.',
        );
    }

    public function nonStringAllowedPackagesEntryIsReportedAndIgnored(): void
    {
        $builder = $this->project(['allowed-packages' => ['demo/scaffold', 42]]);

        $builder->createStubFile('demo/scaffold', 'a.txt', "a\n");

        Assert::same(
            $this->dispatch($builder, ['copy' => ['a.txt']]),
            ConsoleMessage::SCAFFOLD_ALLOWED_ENTRY_IGNORED->getMessage() . PHP_EOL
            . ExpectedSummary::line(1, 0) . PHP_EOL,
            'The ignored entry must be reported before the summary.',
        );
        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/a.txt",
            "a\n",
            'Valid provider must be scaffolded.',
        );
    }

    public function orphansAreListedAfterTheProviderDropsAFile(): void
    {
        $builder = $this->project();

        $builder->createStubFile('demo/scaffold', 'a.txt', "a\n");
        $builder->createStubFile('demo/scaffold', 'old.txt', "old\n");

        $this->dispatch($builder, ['copy' => ['a.txt', 'old.txt']]);

        Assert::same(
            $this->dispatch($builder, ['copy' => ['a.txt']]),
            ConsoleMessage::SCAFFOLD_ORPHAN_HEADER->getMessage(1, ConsoleMessage::FRAGMENT_FILE->getMessage())
            . PHP_EOL
            . ConsoleMessage::SCAFFOLD_ORPHAN_ENTRY->getMessage('old.txt', 'demo/scaffold') . PHP_EOL
            . ExpectedSummary::line(0, 1, ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_ORPHAN)) . PHP_EOL,
            'Orphan must be listed with the prune hint and counted.',
        );
        FileAssert::fileExists(
            "{$builder->getProjectRoot()}/old.txt",
            'Composer runs never delete orphans.',
        );
        Assert::same(
            array_keys((new LockFile($builder->getProjectRoot()))->read()['files']),
            ['a.txt', 'old.txt'],
            "Orphan entry 'old.txt' must stay in the lock.",
        );
    }

    public function throwScaffoldExceptionAfterSummaryWhenAFileFailsToApply(): void
    {
        $builder = $this->project();

        $builder->createStubFile('demo/scaffold', 'a.txt', "a\n");
        $builder->createStubFile('demo/scaffold', 'evil.json', '{}');

        $io = new BufferIO();

        $exception = $this->failingDispatch(
            $builder,
            ['copy' => ['a.txt', ['from' => 'evil.json', 'to' => 'scaffold-lock.json']]],
            $io,
        );

        Assert::same(
            $exception?->getMessage(),
            Message::SCAFFOLD_FILES_FAILED->getMessage(1, Message::SCAFFOLD_FRAGMENT_FAILED_FILE->getMessage()),
            'Exception must carry the error count.',
        );
        Assert::same(
            $io->getOutput(),
            ConsoleMessage::SCAFFOLD_APPLY_FAILED->getMessage(
                'scaffold-lock.json',
                Message::PATH_RESERVED->getMessage('scaffold-lock.json'),
            ) . PHP_EOL
            . ExpectedSummary::line(1, 0, ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_ERROR)) . PHP_EOL,
            'Failure must be reported and the summary must close the output.',
        );
        Assert::same(
            array_keys((new LockFile($builder->getProjectRoot()))->read()['files']),
            ['a.txt'],
            "Successful file 'a.txt' must still be recorded.",
        );
    }

    public function throwScaffoldExceptionWhenAnAllowedManifestIsInvalid(): void
    {
        $builder = $this->project();

        $io = new BufferIO();

        $exception = $this->failingDispatch($builder, ['copy' => ['a.txt'], 'mdoes' => []], $io);

        $expected = Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage(
            'demo/scaffold',
            Message::MANIFEST_KEY_UNKNOWN->getMessage(
                'mdoes',
                'allowed-packages, auto, copy, exclude, manifest, merge, modes',
            ),
        );

        Assert::same(
            $exception?->getMessage(),
            $expected,
            'Exception must name the provider.',
        );
        Assert::same(
            $io->getOutput(),
            $expected . PHP_EOL
            . ExpectedSummary::line(0, 0, ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_ERROR)) . PHP_EOL,
            'Failure must be reported and the summary must close the output.',
        );
        FileAssert::fileDoesNotExist(
            "{$builder->getProjectRoot()}/scaffold-lock.json",
            'Nothing may be recorded.',
        );
    }

    /**
     * Ensures a corrupt lock aborts the run with the chained cause after the summary, applying nothing.
     */
    public function throwScaffoldExceptionWhenTheLockIsCorrupt(): void
    {
        $builder = $this->project();

        $builder->createStubFile('demo/scaffold', 'a.txt', "a\n");
        $builder->createProjectFile('scaffold-lock.json', '{ not valid json');

        $io = new BufferIO();

        $exception = $this->failingDispatch($builder, ['copy' => ['a.txt']], $io);

        $expected = Message::SCAFFOLD_ABORTED->getMessage(
            Message::LOCK_INVALID_JSON->getMessage(
                (new LockFile((string) realpath($builder->getProjectRoot())))->getPath(),
                'Syntax error',
            ),
        );

        Assert::same(
            [$exception?->getMessage(), $exception?->getCode(), get_debug_type($exception?->getPrevious())],
            [$expected, 0, RuntimeException::class],
            "Exception must carry the cause, code '0' and the chained 'RuntimeException'.",
        );
        Assert::same(
            $io->getOutput(),
            $expected . PHP_EOL
            . ExpectedSummary::line(0, 0, ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_ERROR)) . PHP_EOL,
            'Abort must be written before the summary.',
        );
        FileAssert::fileDoesNotExist(
            "{$builder->getProjectRoot()}/a.txt",
            'Nothing may be applied.',
        );
    }

    /**
     * Dispatches `post-install-cmd` with `demo/scaffold` shipping `$manifest` and returns the exception it throws, or
     * `null`; the output stays in `$io`.
     *
     * @param array<string, mixed> $manifest Raw `extra.scaffold` of `demo/scaffold`.
     */
    private function failingDispatch(
        FakeProjectBuilder $builder,
        array $manifest,
        BufferIO $io,
    ): ScaffoldException|null {
        try {
            $this->dispatch($builder, $manifest, io: $io);
        } catch (ScaffoldException $e) {
            return $e;
        }

        return null;
    }
}
