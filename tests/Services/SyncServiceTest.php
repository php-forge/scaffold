<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Services;

use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Services\SyncService;
use PHPForge\Scaffold\Tests\Support\{BufferedOutputWriter, ExpectedSummary, FileAssert, InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

use function file_get_contents;
use function json_encode;
use function mkdir;
use function preg_quote;
use function symlink;
use function unlink;

/**
 * Unit tests for {@see SyncService} applying the providers outside Composer and previewing the result.
 */
#[Test]
#[Covers(SyncService::class)]
final class SyncServiceTest
{
    use TempDirectoryTrait;

    public function bootstrapsProjectWithAutoDisabledAndIsIdempotent(): void
    {
        $project = $this->seedProject();

        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out),
            0,
            "Exit code must be '0'.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "a\n",
            'Replace file must be written.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/.gitignore",
            "/vendor\n",
            'Append file must be written.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::withCommand('sync', 2, 0) . PHP_EOL,
            'Summary must count the written files.',
        );
        Assert::same(
            $out->stderrBuffer,
            '',
            'No warning expected.',
        );

        $lock = file_get_contents(
            "{$this->tempDir}/scaffold-lock.json",
        );

        $again = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $again),
            0,
            "Exit code of the second sync must be '0'.",
        );
        Assert::same(
            $again->stdoutBuffer,
            ExpectedSummary::withCommand('sync', 0, 2) . PHP_EOL,
            'Second run must change nothing.',
        );
        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $lock,
            'Lock must be stable.',
        );
    }

    public function dryRunCountsUnchangedFilesAndSucceedsWithoutExitCode(): void
    {
        $project = $this->seedProject();

        (new SyncService())
            ->run($project->context(), new BufferedOutputWriter());

        $project->stub('p/a', 'a.txt', "a2\n");

        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out, dryRun: true),
            0,
            "Exit code must be '0': changes fail the preview only with '--exit-code'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('a.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1 @@\n-a\n+a2" . PHP_EOL
            . ConsoleMessage::DIFF_NO_CHANGES->getMessage('.gitignore') . PHP_EOL
            . ConsoleMessage::SYNC_DRY_RUN_SUMMARY->getMessage(
                1,
                ConsoleMessage::FRAGMENT_FILE->getMessage(),
                1,
                0,
                0,
                ConsoleMessage::FRAGMENT_ERRORS->getMessage(),
            ) . PHP_EOL,
            'Preview must show the change and count the unchanged file.',
        );
    }

    public function dryRunCountsUserModifiedReplaceFileOnlyWithForce(): void
    {
        $project = $this->seedProject();

        (new SyncService())
            ->run($project->context(), new BufferedOutputWriter());

        $project->file('a.txt', "mine\n");

        $plain = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $plain, dryRun: true, exitCode: true),
            0,
            "Exit code must be '0': a skipped user edit is not a change.",
        );
        Assert::same(
            $plain->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage(
                'a.txt',
                'replace',
                'p/a',
                ConsoleMessage::FRAGMENT_USER_MODIFIED_PREVIEW->getMessage(),
            ) . PHP_EOL
            . "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1 @@\n-mine\n+a" . PHP_EOL
            . ConsoleMessage::DIFF_NO_CHANGES->getMessage('.gitignore') . PHP_EOL
            . ConsoleMessage::SYNC_DRY_RUN_SUMMARY->getMessage(
                0,
                ConsoleMessage::FRAGMENT_FILES->getMessage(),
                2,
                0,
                0,
                ConsoleMessage::FRAGMENT_ERRORS->getMessage(),
            ) . PHP_EOL,
            'User edit must be flagged and counted as unchanged.',
        );
        Assert::same(
            (new SyncService())->run(
                $project->context(),
                new BufferedOutputWriter(),
                dryRun: true,
                exitCode: true,
                force: true,
            ),
            1,
            "Exit code must be '1': a forced overwrite is a change.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "mine\n",
            'Dry run must not write.',
        );
    }

    public function dryRunPreviewsPendingFilesAndOrphansWithoutWriting(): void
    {
        $project = $this->seedProject()
            ->file('old.txt', "old\n")
            ->track('old.txt', 'p/a');

        $before = file_get_contents(
            "{$this->tempDir}/scaffold-lock.json",
        );

        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out, dryRun: true, exitCode: true),
            1,
            "Exit code must be '1' for pending files with '--exit-code'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('a.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/a.txt\n+++ b/a.txt\n@@ -0,0 +1 @@\n+a" . PHP_EOL
            . ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('.gitignore', 'append', 'p/a', '') . PHP_EOL
            . "--- a/.gitignore\n+++ b/.gitignore\n@@ -0,0 +1 @@\n+/vendor" . PHP_EOL
            . ConsoleMessage::SYNC_DRY_RUN_SUMMARY->getMessage(
                2,
                ConsoleMessage::FRAGMENT_FILES->getMessage(),
                0,
                1,
                0,
                ConsoleMessage::FRAGMENT_ERRORS->getMessage(),
            ) . PHP_EOL,
            'Pending files must be previewed and counted with the orphan.',
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::SCAFFOLD_ORPHAN_HEADER->getMessage(1, ConsoleMessage::FRAGMENT_FILE->getMessage()) . PHP_EOL
            . ConsoleMessage::SCAFFOLD_ORPHAN_ENTRY->getMessage('old.txt', 'p/a') . PHP_EOL,
            'Orphans must be listed.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/a.txt",
            'Dry run must not write files.',
        );
        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $before,
            'Lock must not change.',
        );

        (new SyncService())
            ->run($project->context(), new BufferedOutputWriter());

        Assert::same(
            (new SyncService())->run($project->context(), new BufferedOutputWriter(), dryRun: true, exitCode: true),
            0,
            "Exit code must be '0' once the project is synced.",
        );
    }

    public function dryRunReportsEscapingStubs(): void
    {
        $project = $this->seedProject()->file('outside.txt', "a\n");

        unlink("{$this->tempDir}/vendor/p/a/stubs/a.txt");

        if (@symlink("{$this->tempDir}/outside.txt", "{$this->tempDir}/vendor/p/a/stubs/a.txt") === false) {
            throw new SkipTest(
                'Symbolic links are not available.',
            );
        }

        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out, dryRun: true),
            1,
            "Exit code must be '1' for an escaping stub.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::COMMAND_FILE_ERROR->getMessage(
                'a.txt',
                Message::PATH_SYMLINK_ESCAPE->getMessage('Source', 'stubs/a.txt'),
            ) . PHP_EOL,
            'Escaping stub must be the only stderr line.',
        );
    }

    public function dryRunReportsUnsafeDestinations(): void
    {
        $project = $this->seedProject()->file(
            'vendor/p/a/composer.json',
            json_encode(
                [
                    'name' => 'p/a',
                    'extra' => [
                        'scaffold' => [
                            'copy' => [
                                ['from' => 'stubs/a.txt', 'to' => 'scaffold-lock.json'],
                                ['from' => 'stubs/a.txt', 'to' => 'a.txt'],
                            ],
                        ],
                    ],
                ],
                JSON_THROW_ON_ERROR,
            ),
        );

        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out, dryRun: true),
            1,
            "Exit code must be '1' for an unsafe destination.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('a.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/a.txt\n+++ b/a.txt\n@@ -0,0 +1 @@\n+a" . PHP_EOL
            . ConsoleMessage::SYNC_DRY_RUN_SUMMARY->getMessage(
                1,
                ConsoleMessage::FRAGMENT_FILE->getMessage(),
                0,
                0,
                1,
                ConsoleMessage::FRAGMENT_ERROR->getMessage(),
            ) . PHP_EOL,
            'Later files must still be previewed and the error counted.',
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::COMMAND_FILE_ERROR->getMessage(
                'scaffold-lock.json',
                Message::PATH_RESERVED->getMessage('scaffold-lock.json'),
            ) . PHP_EOL,
            'Reserved destination must be reported.',
        );
    }

    public function failsWhenAFileCannotBeApplied(): void
    {
        $project = $this->seedProject()->file(
            'vendor/p/a/composer.json',
            json_encode(
                [
                    'name' => 'p/a',
                    'extra' => [
                        'scaffold' => [
                            'copy' => [
                                ['from' => 'stubs/a.txt', 'to' => 'a.txt'],
                                ['from' => 'stubs/a.txt', 'to' => 'scaffold-lock.json'],
                            ],
                        ],
                    ],
                ],
                JSON_THROW_ON_ERROR,
            ),
        );

        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out),
            1,
            "Exit code must be '1' when a file fails.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::SCAFFOLD_APPLY_FAILED->getMessage(
                'scaffold-lock.json',
                Message::PATH_RESERVED->getMessage('scaffold-lock.json'),
            ) . PHP_EOL,
            'Cause must be the only stderr line.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::withCommand('sync', 1, 0, ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_ERROR))
            . PHP_EOL,
            'Summary must count the error.',
        );
    }

    public function failsWhenTheLockCannotBeWritten(): void
    {
        $project = $this->seedProject();

        // A non-empty directory at the lock path reads as an absent lock, and makes the final rename fail.
        mkdir("{$this->tempDir}/scaffold-lock.json/entry", 0o777, true);

        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out),
            1,
            "Exit code must be '1' when the lock cannot be written.",
        );
        // The reason names a temporary file with a unique suffix and depends on the platform, so it is a pattern.
        Assert::string($out->stderrBuffer)->matchesRegex(
            '/\A' . preg_quote(
                ConsoleMessage::SYNC_ABORTED->getMessage(
                    Message::LOCK_UNWRITABLE->getMessage((new LockFile($this->tempDir))->getPath()) . ' Reason: rename(',
                ),
                '/',
            ) . '.+\R\z/',
            "Cause must be the only stderr line, with the failed 'rename()'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::withCommand('sync', 2, 0, ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_ERROR))
            . PHP_EOL,
            'Summary must count the error.',
        );
    }

    public function failsWithoutWritingWhenAProviderManifestIsInvalid(): void
    {
        $project = $this
            ->seedProject()
            ->file('vendor/p/a/composer.json', '{"extra": {"scaffold": {"copi": []}}}');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out),
            1,
            "Exit code must be '1' for an invalid manifest.",
        );
        Assert::same(
            $out->stderrBuffer,
            Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage(
                'p/a',
                Message::MANIFEST_KEY_UNKNOWN->getMessage(
                    'copi',
                    'allowed-packages, auto, copy, exclude, manifest, merge, modes',
                ),
            ) . PHP_EOL,
            'Cause must be the only stderr line.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::withCommand('sync', 0, 0, ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_ERROR))
            . PHP_EOL,
            'Summary must still be printed.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/scaffold-lock.json",
            'Nothing may be recorded.',
        );
    }

    public function forceOverwritesUserModifiedReplaceFile(): void
    {
        $project = $this->seedProject();

        (new SyncService())
            ->run($project->context(), new BufferedOutputWriter());

        $project->file('a.txt', "mine\n");

        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out),
            0,
            "Exit code of a plain sync must be '0'.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "mine\n",
            'Edit must survive a plain sync.',
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::SCAFFOLD_PREFIXED->getMessage(
                ConsoleMessage::SCAFFOLD_USER_MODIFIED_SKIPPED->getMessage('a.txt'),
            ) . PHP_EOL,
            'Skip must be warned.',
        );
        Assert::same(
            (new SyncService())->run($project->context(), new BufferedOutputWriter(), force: true),
            0,
            "Exit code of a forced sync must be '0'.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "a\n",
            'Force must restore the stub.',
        );
    }

    public function notInstalledProviderIsWarnedInBothModes(): void
    {
        $project = $this->seedProject(['p/a', 'p/missing']);

        $warning = ConsoleMessage::SCAFFOLD_PROVIDER_NOT_INSTALLED->getMessage('p/missing') . PHP_EOL;

        $preview = new BufferedOutputWriter();

        (new SyncService())
            ->run($project->context(), $preview, dryRun: true);

        Assert::same(
            $preview->stderrBuffer,
            $warning,
            'Dry run must warn.',
        );

        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out),
            0,
            "Exit code must be '0': a missing provider is a warning.",
        );
        Assert::same(
            $out->stderrBuffer,
            $warning,
            'Sync must warn.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::withCommand('sync', 2, 0, ExpectedSummary::part(1, ConsoleMessage::FRAGMENT_WARNING))
            . PHP_EOL,
            'Warning must be counted.',
        );
    }

    public function withoutAllowedPackagesNothingIsSynced(): void
    {
        $project = $this->seedProject([]);
        $out = new BufferedOutputWriter();

        Assert::same(
            (new SyncService())->run($project->context(), $out),
            0,
            "Exit code must be '0': an empty allowlist is not an error.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::SYNC_NO_ALLOWED_PACKAGES->getMessage() . PHP_EOL,
            'Notice must be the only output.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/scaffold-lock.json",
            'No lock may be created.',
        );
    }

    /**
     * Builds a project with `auto: false` and provider `p/a` shipping a replace and an append file.
     *
     * @param list<string> $allowed Providers allowed to write files.
     */
    private function seedProject(array $allowed = ['p/a']): InspectionProject
    {
        return (new InspectionProject($this->tempDir))
            ->file(
                'composer.json',
                json_encode(
                    ['extra' => ['scaffold' => ['allowed-packages' => $allowed, 'auto' => false]]],
                    JSON_THROW_ON_ERROR,
                ),
            )
            ->installed(['p/a' => '1.0.0'])
            ->provider('p/a', ['a.txt' => "a\n", '.gitignore' => "/vendor\n"], ['.gitignore' => 'append']);
    }
}
