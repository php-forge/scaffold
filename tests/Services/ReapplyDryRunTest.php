<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Services;

use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Scaffold\PathResolver;
use PHPForge\Scaffold\Services\{DiffService, ReapplyService, ReapplySummary};
use PHPForge\Scaffold\Tests\Provider\ReapplyServiceProvider;
use PHPForge\Scaffold\Tests\Support\{
    BufferedOutputWriter,
    ExpectedSummary,
    FileAssert,
    InspectionProject,
    TempDirectoryTrait,
};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\{DataProvider, DataSet};
use Testo\Test;

use function file_get_contents;
use function file_put_contents;
use function json_encode;
use function realpath;

/**
 * Unit tests for {@see ReapplyService} covering `--dry-run`, the summary line, the allowlist, orphans and exit codes.
 *
 * {@see ReapplyServiceProvider} for test case data providers.
 */
#[Test]
#[Covers(ReapplyService::class)]
#[Covers(ReapplySummary::class)]
final class ReapplyDryRunTest
{
    use TempDirectoryTrait;

    public function dryRunContinuesAfterAStubWritePreview(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "a1\n", 'b.txt' => "b1\n"])
            ->file('a.txt', "a1\n")
            ->file('b.txt', "b1\n")
            ->track('a.txt', 'p/a')
            ->track('b.txt', 'p/a')
            ->stub('p/a', 'a.txt', "a2\n")
            ->stub('p/a', 'b.txt', "b2\n");

        $out = new BufferedOutputWriter();

        $this->reapply($project, $out, dryRun: true);

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('a.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1 @@\n-a1\n+a2" . PHP_EOL
            . ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('b.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/b.txt\n+++ b/b.txt\n@@ -1 +1 @@\n-b1\n+b2" . PHP_EOL
            . ExpectedSummary::reapplyDryRun(2, 0, 0, 0) . PHP_EOL,
            'Both previews must be printed and counted.',
        );
    }

    #[DataProvider(ReapplyServiceProvider::class . '::dryRunExitCodes')]
    public function dryRunExitCodeReportsPendingChangesOnlyWhenRequested(
        string $stub,
        bool $exitCode,
        int $expectedExitCode,
        string $expectedStdout,
    ): void {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "v1\n"])
            ->file('a.txt', "v1\n")
            ->track('a.txt', 'p/a')
            ->stub('p/a', 'a.txt', $stub);

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, dryRun: true, exitCode: $exitCode),
            $expectedExitCode,
            'Exit code of the dry run must match.',
        );
        Assert::same(
            $out->stdoutBuffer,
            $expectedStdout,
            'Preview and summary must match.',
        );
    }

    public function dryRunOfAnAppendFileCountsUnchangedAndReportsAMissingStub(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['.gitignore' => "/vendor\n", 'list.txt' => "a\n"], ['.gitignore' => 'append'])
            ->file('.gitignore', "/vendor\n")
            ->file('list.txt', "a\n")
            ->track('.gitignore', 'p/a', 'append', ['merge' => 'unknown'])
            ->track('list.txt', 'p/a', 'append')
            ->delete('vendor/p/a/stubs/list.txt');

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, dryRun: true),
            1,
            "Exit code must be '1' when a stub is missing.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_MERGE_STRATEGY_UNKNOWN->getMessage('unknown', '.gitignore') . PHP_EOL
            . ConsoleMessage::REAPPLY_STUB_NOT_FOUND->getMessage(
                PathResolver::source((string) realpath("{$this->tempDir}/vendor/p/a"), 'stubs/list.txt'),
            ) . PHP_EOL,
            'Unknown strategy and missing stub must be reported.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_NO_CHANGES->getMessage('.gitignore') . PHP_EOL
            . ExpectedSummary::reapplyDryRun(0, 1, 0, 1) . PHP_EOL,
            'Summary must count both entries.',
        );
    }
    public function dryRunPrintsTheDiffServiceOutputAndWritesNothing(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "v1\n", '.gitignore' => "/vendor\n"], ['.gitignore' => 'append'])
            ->file('a.txt', "v1\n")
            ->file('.gitignore', "/vendor\n")
            ->track('a.txt', 'p/a')
            ->track('.gitignore', 'p/a', 'append')
            ->stub('p/a', 'a.txt', "v2\n")
            ->stub('p/a', '.gitignore', "/vendor\n/.config/gh\n");

        $lockBefore = file_get_contents(
            "{$this->tempDir}/scaffold-lock.json",
        );

        $diff = new BufferedOutputWriter();

        (new DiffService())
            ->run($project->context(), $diff);

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, dryRun: true),
            0,
            "Exit code of a dry run without '--exit-code' must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            $diff->stdoutBuffer . ExpectedSummary::reapplyDryRun(2, 0, 0, 0) . PHP_EOL,
            'Dry run must print the diff output and a summary.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "v1\n",
            'Replace file must be untouched.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/.gitignore",
            "/vendor\n",
            'Append file must be untouched.',
        );
        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $lockBefore,
            'Lock must be untouched.',
        );
    }

    public function dryRunWithForceShowsTheStubOverwriteOfAnAppendFile(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['.gitignore' => "/vendor\n"], ['.gitignore' => 'append'])
            ->file('.gitignore', "/vendor\n/mine\n")
            ->track('.gitignore', 'p/a', 'append');

        $out = new BufferedOutputWriter();

        $this->reapply($project, $out, force: true, dryRun: true);

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('.gitignore', 'append', 'p/a', '') . PHP_EOL
            . "--- a/.gitignore\n+++ b/.gitignore\n@@ -1,2 +1 @@\n /vendor\n-/mine" . PHP_EOL
            . ExpectedSummary::reapplyDryRun(1, 0, 0, 0) . PHP_EOL,
            'Force must preview the stub overwrite of the append file.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/.gitignore",
            "/vendor\n/mine\n",
            'Nothing may be written.',
        );
    }

    public function forcedDryRunReportsAnUnreadableDestinationAndKeepsGoing(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "a2\n", 'b.txt' => "b2\n"])
            ->file('a.txt', "a1\n")
            ->file('b.txt', "b1\n")
            ->track('a.txt', 'p/a')
            ->track('b.txt', 'p/a');

        $this->restrictFileAccess("{$this->tempDir}/a.txt", 0o000);

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, force: true, dryRun: true),
            1,
            "Exit code must be '1' when a destination cannot be read.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_FAILED->getMessage(
                'a.txt',
                Message::FILESYSTEM_FAILED->getMessage(
                    Message::SERVICE_CURRENT_FILE_UNREADABLE->getMessage("{$this->tempDir}/a.txt"),
                    "file_get_contents({$this->tempDir}/a.txt): Failed to open stream: Permission denied",
                ),
            ) . PHP_EOL,
            'Unreadable destination must be reported with its reason.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('b.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/b.txt\n+++ b/b.txt\n@@ -1 +1 @@\n-b1\n+b2" . PHP_EOL
            . ExpectedSummary::reapplyDryRun(1, 0, 0, 1) . PHP_EOL,
            'Next file must still be previewed and the error counted.',
        );
    }

    public function givenContextIsUsedInsteadOfTheComposerJsonOnDisk(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a');

        $context = $project->context();

        $project->composer([]);

        $out = new BufferedOutputWriter();

        Assert::same(
            (new ReapplyService())->run($context, $out),
            0,
            "Exit code must be '0' with the allowlist of the given context.",
        );
        Assert::same(
            $out->stderrBuffer,
            '',
            'No allowlist rejection may be reported.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('a.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 0) . PHP_EOL,
            'Entry must be reapplied.',
        );
    }

    public function lockIsNotRewrittenWhenNothingWasUpdated(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->file('a.txt', "mine\n");

        $compact = json_encode($project->lock()->read(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        file_put_contents(
            "{$this->tempDir}/scaffold-lock.json",
            $compact,
        );

        $this->reapply($project, new BufferedOutputWriter());

        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $compact,
            'Lock bytes must be unchanged.',
        );
    }

    public function notAllowedProviderIsSkippedAndFails(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/denied', ['d.txt' => "d\n"])
            ->file('d.txt', "x\n")
            ->track('d.txt', 'p/denied');

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, force: true),
            1,
            "Exit code must be '1' for an allowlist rejection.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/d.txt",
            "x\n",
            'File must never be written.',
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::COMMAND_PROVIDER_NOT_ALLOWED->getMessage('d.txt', 'p/denied') . PHP_EOL,
            'Rejection must be reported.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::reapply(0, 0, 0, 1) . PHP_EOL,
            'Summary must count the error.',
        );
    }

    #[DataSet([false], 'without force')]
    #[DataSet([true], 'with force')]
    public function orphanedEntryIsSkippedWithThePruneHint(bool $force): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->file('old.txt', "old\n")
            ->track('a.txt', 'p/a')
            ->track('old.txt', 'p/a');

        $lockBefore = file_get_contents(
            "{$this->tempDir}/scaffold-lock.json",
        );

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, force: $force),
            0,
            "Exit code of an orphan skip must be '0'.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_ORPHANED->getMessage('old.txt') . PHP_EOL,
            'Orphan must be reported with the prune hint.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('a.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 1, 0, 'orphaned') . PHP_EOL,
            'Summary must count the orphan as skipped.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/old.txt",
            "old\n",
            'Orphan file must be untouched.',
        );
        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $lockBefore,
            'Lock must be unchanged: the shipped file was already synced and the orphan entry is kept.',
        );
    }

    public function orphanedEntryOfAnAllowedProviderThatIsNotInstalledIsSkipped(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a', 'p/gone'])
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->file('g.txt', "g\n")
            ->track('a.txt', 'p/a')
            ->track('g.txt', 'p/gone')
            ->installed(['p/a' => '1.0.0']);

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out),
            0,
            "Exit code of an orphan skip must be '0'.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_ORPHANED->getMessage('g.txt') . PHP_EOL,
            'Orphan must be reported instead of a missing stub.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('a.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 1, 0, 'orphaned') . PHP_EOL,
            'Summary must count the orphan as skipped.',
        );
    }

    public function skippedEntriesDoNotStopTheIteration(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['b-ejected.txt' => "b\n", 'd-shipped.txt' => "d2\n"])
            ->provider('p/denied', ['a-denied.txt' => "x\n"])
            ->file('a-denied.txt', "x\n")
            ->file('b-ejected.txt', "b\n")
            ->file('c-orphan.txt', "c\n")
            ->file('d-shipped.txt', "d1\n")
            ->track('a-denied.txt', 'p/denied')
            ->track('b-ejected.txt', 'p/a', extra: ['ejected' => true])
            ->track('c-orphan.txt', 'p/a')
            ->track('d-shipped.txt', 'p/a');

        $out = new BufferedOutputWriter();

        $this->reapply($project, $out);

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('d-shipped.txt') . PHP_EOL
            . ExpectedSummary::reapply(
                1,
                0,
                2,
                1,
                ConsoleMessage::FRAGMENT_COUNT->getMessage(1, 'ejected'),
                ConsoleMessage::FRAGMENT_COUNT->getMessage(1, 'orphaned'),
            ) . PHP_EOL,
            'Entry after every skip must still be reapplied.',
        );
    }

    public function summaryListsSkipReasons(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "a\n", 'b.txt' => "b\n", 'e.txt' => "e\n"], ['b.txt' => 'preserve'])
            ->file('a.txt', "a\n")
            ->file('b.txt', "b\n")
            ->file('e.txt', "e\n")
            ->track('a.txt', 'p/a')
            ->track('b.txt', 'p/a', 'preserve')
            ->track('e.txt', 'p/a', extra: ['ejected' => true])
            ->file('a.txt', "mine\n");

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out),
            0,
            "Exit code of skips must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::reapply(
                0,
                0,
                3,
                0,
                ConsoleMessage::FRAGMENT_COUNT->getMessage(1, 'user-modified'),
                ConsoleMessage::FRAGMENT_COUNT->getMessage(1, 'preserve'),
                ConsoleMessage::FRAGMENT_COUNT->getMessage(1, 'ejected'),
            ) . PHP_EOL,
            'Summary must list the skip reasons.',
        );
    }

    /**
     * Runs `reapply` over every tracked file of the project.
     */
    private function reapply(
        InspectionProject $project,
        BufferedOutputWriter $out,
        bool $force = false,
        bool $dryRun = false,
        bool $exitCode = false,
    ): int {
        return (new ReapplyService())->run(
            $project->context(),
            $out,
            force: $force,
            dryRun: $dryRun,
            exitCode: $exitCode,
        );
    }
}
