<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Services;

use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Services\PruneService;
use PHPForge\Scaffold\Tests\Support\{BufferedOutputWriter, FileAssert, InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataSet;
use Testo\Test;

use function array_key_exists;
use function array_keys;
use function chmod;
use function error_clear_last;
use function error_get_last;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function json_encode;
use function mkdir;
use function symlink;

/**
 * Unit tests for {@see PruneService} previewing and removing orphaned lock entries and files.
 */
#[Test]
#[Covers(PruneService::class)]
final class PruneServiceTest
{
    use TempDirectoryTrait;

    public function deleteModifiedAlsoDeletesFilesWithConsumerContent(): void
    {
        $project = $this->seedOrphans();

        $out = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $out, true, true),
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            implode(
                PHP_EOL,
                [
                    ConsoleMessage::PRUNE_DELETED->getMessage(
                        '.gitignore',
                        ConsoleMessage::FRAGMENT_PARENTHESIZED->getMessage('merged'),
                    ),
                    ConsoleMessage::PRUNE_DELETED->getMessage(
                        'edited.txt',
                        ConsoleMessage::FRAGMENT_PARENTHESIZED->getMessage('modified'),
                    ),
                    ConsoleMessage::PRUNE_DELETED->getMessage('gone/deep/old.txt', ''),
                    ConsoleMessage::PRUNE_DELETED->getMessage('legacy.txt', ''),
                    ConsoleMessage::PRUNE_ENTRY_DROPPED->getMessage('missing.txt', 'missing'),
                    ConsoleMessage::PRUNE_SUMMARY->getMessage(5, 0, 0, ConsoleMessage::FRAGMENT_ERRORS->getMessage()),
                    '',
                ],
            ),
            'Every orphan must be deleted and counted as removed.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/edited.txt",
            'Modified orphan must be deleted.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/.gitignore",
            'Merged orphan must be deleted.',
        );
    }

    public function dryRunListsIntegerLikeAndUnsafeOrphansWithMinimumColumnWidths(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->installed(['p/a' => '1.0.0'])
            ->provider('p/a', ['kept.txt' => "kept\n"])
            ->file('1', "one\n")
            ->track('1', 'p/a')
            ->track('/x', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $out),
            0,
            "Exit code of a preview must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            implode(
                PHP_EOL,
                [
                    'File  Provider  Disk        With --force',
                    '----------------------------------------',
                    '/x    p/a       unsafe      drop entry',
                    '1     p/a       unmodified  delete',
                    ConsoleMessage::PRUNE_DRY_RUN_SUMMARY->getMessage(
                        2,
                        0,
                        0,
                        ConsoleMessage::FRAGMENT_ERRORS->getMessage(),
                    ),
                    '',
                ],
            ),
            'File column must keep its minimum width.',
        );
    }

    public function dryRunListsOrphansWithoutTouchingAnything(): void
    {
        $project = $this->seedOrphans();
        $before = file_get_contents("{$this->tempDir}/scaffold-lock.json");
        $out = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $out),
            0,
            "Exit code of a preview must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            self::orphanTable(
                ConsoleMessage::PRUNE_DRY_RUN_SUMMARY->getMessage(3, 2, 0, ConsoleMessage::FRAGMENT_ERRORS->getMessage()),
            ),
            'Preview table must list every orphan.',
        );
        Assert::same(
            $out->stderrBuffer,
            '',
            'No warning expected.',
        );
        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $before,
            'Lock must stay untouched.',
        );
        FileAssert::fileExists("{$this->tempDir}/gone/deep/old.txt", 'Files must stay on disk.');
    }

    public function dryRunSummaryUsesTheSingularForOneError(): void
    {
        $project = $this->seedOrphans()
            ->composer(['p/a', 'p/broken'])
            ->installed(['p/a' => '1.0.0', 'p/old' => '1.0.0', 'p/broken' => '1.0.0'])
            ->file('vendor/p/broken/composer.json', '{broken');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $out),
            1,
            "Exit code must be '1' for a broken manifest.",
        );
        Assert::same(
            $out->stdoutBuffer,
            self::orphanTable(
                ConsoleMessage::PRUNE_DRY_RUN_SUMMARY->getMessage(3, 2, 1, ConsoleMessage::FRAGMENT_ERROR->getMessage()),
            ),
            'One error must use the singular.',
        );
    }

    public function exitCodeReportsOrphansInPreview(): void
    {
        Assert::same(
            (new PruneService())->run($this->seedOrphans()->context(), new BufferedOutputWriter(), exitCode: true),
            1,
            "Exit code must be '1' for orphans with '--exit-code'.",
        );
    }

    public function failedDeletionIsReportedAndKeepsTheEntry(): void
    {
        $project = $this->seedOrphans();

        $this->restrictFileAccess("{$this->tempDir}/gone/deep", 0o555);

        $out = new BufferedOutputWriter();

        error_clear_last();

        try {
            $exitCode = (new PruneService())
                ->run($project->context(), $out, true);
        } finally {
            chmod("{$this->tempDir}/gone/deep", 0o755);
        }

        Assert::null(
            error_get_last(),
            'The failure must be captured, not raised.',
        );
        Assert::same(
            $exitCode,
            1,
            "Exit code must be '1' for an I/O failure.",
        );
        Assert::same(
            $out->stderrBuffer,
            Message::FILESYSTEM_FAILED->getMessage(
                ConsoleMessage::PRUNE_FILE_DELETE_FAILED->getMessage("{$this->tempDir}/gone/deep/old.txt"),
                "unlink({$this->tempDir}/gone/deep/old.txt): Permission denied",
            ) . PHP_EOL,
            'Failure must be reported with its reason.',
        );
        Assert::true(
            array_key_exists('gone/deep/old.txt', $project->lock()->read()['files']),
            'Entry of an undeleted file must stay.',
        );
        Assert::string($out->stdoutBuffer)->endsWith(
            ConsoleMessage::PRUNE_SUMMARY->getMessage(2, 2, 1, ConsoleMessage::FRAGMENT_ERROR->getMessage()) . PHP_EOL,
            'Summary must count the error.',
        );
    }

    public function failedDirectoryRemovalIsReportedAfterTheFileIsDeleted(): void
    {
        $project = $this->seedOrphans();

        // 'gone/deep' stays writable, so its file is deleted, but 'gone' blocks the removal of the empty 'gone/deep'.
        $this->restrictFileAccess("{$this->tempDir}/gone", 0o555);

        $out = new BufferedOutputWriter();

        try {
            $exitCode = (new PruneService())
                ->run($project->context(), $out, true);
        } finally {
            chmod("{$this->tempDir}/gone", 0o755);
        }

        Assert::same(
            $exitCode,
            0,
            "Exit code must be '0' when only an empty parent directory cannot be removed.",
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/gone/deep/old.txt",
            'The orphan file must be deleted.',
        );
        Assert::same(
            $out->stderrBuffer,
            Message::FILESYSTEM_FAILED->getMessage(
                ConsoleMessage::PRUNE_DIRECTORY_REMOVE_FAILED->getMessage("{$this->tempDir}/gone/deep"),
                "rmdir({$this->tempDir}/gone/deep): Permission denied",
            ) . PHP_EOL,
            'The failed directory removal must be reported with its reason.',
        );
    }

    public function forceDeletesOnlyUnmodifiedOrphansAndCleansTheLock(): void
    {
        $project = $this->seedOrphans();
        $out = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $out, true),
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            implode(
                PHP_EOL,
                [
                    ConsoleMessage::PRUNE_KEPT->getMessage('.gitignore', 'merged'),
                    ConsoleMessage::PRUNE_KEPT->getMessage('edited.txt', 'modified'),
                    ConsoleMessage::PRUNE_DELETED->getMessage('gone/deep/old.txt', ''),
                    ConsoleMessage::PRUNE_DELETED->getMessage('legacy.txt', ''),
                    ConsoleMessage::PRUNE_ENTRY_DROPPED->getMessage('missing.txt', 'missing'),
                    ConsoleMessage::PRUNE_SUMMARY->getMessage(3, 2, 0, ConsoleMessage::FRAGMENT_ERRORS->getMessage()),
                    '',
                ],
            ),
            'Every orphan must be reported.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/gone/deep/old.txt",
            'Unmodified orphan must be deleted.',
        );
        FileAssert::directoryDoesNotExist(
            "{$this->tempDir}/gone",
            'Empty parents must be removed.',
        );
        FileAssert::directoryExists(
            $this->tempDir,
            'Project root must stay.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/legacy.txt",
            'Orphan of a removed provider must be deleted.',
        );
        FileAssert::fileExists(
            "{$this->tempDir}/edited.txt",
            'Modified orphan must stay.',
        );
        FileAssert::fileExists(
            "{$this->tempDir}/.gitignore",
            'Merged orphan must stay.',
        );
        FileAssert::fileExists(
            "{$this->tempDir}/kept.txt",
            'Shipped file must stay.',
        );

        $lock = $project->lock()->read();

        Assert::same(
            array_keys($lock['files']),
            ['ej.txt', 'kept.txt'],
            'Shipped and ejected entries must remain.',
        );
        Assert::same(
            array_keys($lock['providers']),
            ['p/a'],
            'Provider without entries must be removed.',
        );

        $again = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $again, true),
            0,
            "Exit code of the second run must be '0'.",
        );
        Assert::same(
            $again->stdoutBuffer,
            ConsoleMessage::PRUNE_NO_ORPHANS->getMessage() . PHP_EOL,
            'Nothing may be left to prune.',
        );
    }

    public function forceKeepsDirectoriesThatStillHoldFiles(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->installed(['p/a' => '1.0.0'])
            ->provider('p/a', ['kept.txt' => "kept\n"])
            ->file('shared/a.txt', "a\n")
            ->file('shared/mine.txt', "mine\n")
            ->track('shared/a.txt', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $out, true),
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            $out->stderrBuffer,
            '',
            'A non-empty directory must not be removed.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/shared/a.txt",
            'Orphan must be deleted.',
        );
        FileAssert::fileExists(
            "{$this->tempDir}/shared/mine.txt",
            'Consumer file must stay.',
        );
    }

    public function forceKeepsEmptyDirectoriesAboveAProjectRootWithTrailingSeparators(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->installed(['p/a' => '1.0.0'])
            ->provider('p/a', ['kept.txt' => "kept\n"])
            ->file('s/a.txt', "a\n")
            ->track('s/a.txt', 'p/a');

        $context = ProjectContext::create("{$this->tempDir}//");

        Assert::same(
            (new PruneService())->run($context, new BufferedOutputWriter(), true),
            0,
            "Exit code must be '0'.",
        );
        FileAssert::directoryDoesNotExist(
            "{$this->tempDir}/s",
            'Emptied directory must be removed.',
        );
        FileAssert::directoryExists(
            $this->tempDir,
            'Project root must stay.',
        );
        Assert::false(
            array_key_exists('s/a.txt', $project->lock()->read()['files']),
            'Entry must be removed.',
        );
    }

    public function forceLeavesSymlinkedDirectoriesInPlace(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->installed(['p/a' => '1.0.0'])
            ->provider('p/a', ['kept.txt' => "kept\n"])
            ->file('real/a.txt', "a\n");

        if (@symlink("{$this->tempDir}/real", "{$this->tempDir}/link") === false) {
            throw new SkipTest('Symbolic links are not available.');
        }

        $project->track('link/a.txt', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $out, true),
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            $out->stderrBuffer,
            '',
            'The symbolic link must not be removed as a directory.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/real/a.txt",
            'Orphan must be deleted through the link.',
        );
        FileAssert::directoryExists(
            "{$this->tempDir}/real",
            'Link target must stay.',
        );
    }

    public function forceRemovesOnlyProvidersThatAreUnusedAndNotAllowedAndInstalled(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a', 'p/idle'])
            ->installed(['p/a' => '1.0.0', 'p/idle' => '1.0.0'])
            ->provider('p/a', ['kept.txt' => "kept\n"])
            ->provider('p/idle', ['idle.txt' => "idle\n"])
            ->file('kept.txt', "kept\n")
            ->track('kept.txt', 'p/a')
            ->file('gone.txt', "gone\n")
            ->track('gone.txt', 'p/a')
            ->file('ej.txt', "mine\n")
            ->track('ej.txt', 'p/denied', extra: ['ejected' => true])
            ->track('stale.txt', 'p/old');

        $data = $project->lock()->read();

        $data['providers']['p/idle'] = ['version' => '1.0.0', 'path' => 'vendor/p/idle'];

        $project->lock()->write($data);

        Assert::same(
            (new PruneService())->run($project->context(), new BufferedOutputWriter(), true),
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            array_keys($project->lock()->read()['providers']),
            ['p/a', 'p/denied', 'p/idle'],
            'Owners and allowed installed providers stay; the unused removed provider goes.',
        );
    }

    #[DataSet([false], 'preview')]
    #[DataSet([true], 'force')]
    public function lockWithoutOrphansOrStaleProvidersIsNeverRewritten(bool $force): void
    {
        $project = $this->seedShippedOnly();
        $lock = (string) file_get_contents("{$this->tempDir}/scaffold-lock.json");

        (new PruneService())
            ->run($project->context(), new BufferedOutputWriter(), $force);

        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $lock,
            'Nothing to drop must leave the lock untouched.',
        );
    }

    public function orphansOfAProviderWhoseManifestFailsAreNeverPruned(): void
    {
        $project = $this->seedOrphans()->file('vendor/p/a/composer.json', '{broken');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $out, true),
            1,
            "Exit code must be '1' when a manifest fails.",
        );
        Assert::same(
            $out->stderrBuffer,
            Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage('p/a', 'Syntax error') . PHP_EOL,
            'Cause must be the only stderr line.',
        );
        FileAssert::fileExists(
            "{$this->tempDir}/gone/deep/old.txt",
            'Files of the failed provider must stay.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/legacy.txt",
            'Other orphans must still be pruned.',
        );
    }

    public function orphanWhoseDestinationIsADirectoryIsAnErrorAndIsNeverDeleted(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->installed(['p/a' => '1.0.0'])
            ->provider('p/a', ['kept.txt' => "kept\n"])
            ->track('dir.txt', 'p/a');

        // A directory where the orphan file is expected cannot be hashed.
        mkdir("{$this->tempDir}/dir.txt");

        $preview = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $preview),
            1,
            "Exit code must be '1' when an orphan cannot be read.",
        );
        Assert::same(
            $preview->stdoutBuffer,
            implode(
                PHP_EOL,
                [
                    'File     Provider  Disk   With --force',
                    '--------------------------------------',
                    'dir.txt  p/a       error  keep',
                    ConsoleMessage::PRUNE_DRY_RUN_SUMMARY->getMessage(
                        0,
                        1,
                        1,
                        ConsoleMessage::FRAGMENT_ERROR->getMessage(),
                    ),
                    '',
                ],
            ),
            "Preview must list the unreadable orphan as 'error' and 'keep'.",
        );

        $forced = new BufferedOutputWriter();

        Assert::same(
            (new PruneService())->run($project->context(), $forced, true, true),
            1,
            "Exit code must be '1' when a forced prune meets an unreadable orphan.",
        );
        FileAssert::directoryExists(
            "{$this->tempDir}/dir.txt",
            "An unreadable orphan must never be deleted, even with '--delete-modified'.",
        );
        Assert::same(
            $forced->stdoutBuffer,
            ConsoleMessage::PRUNE_KEPT->getMessage('dir.txt', 'error') . PHP_EOL
            . ConsoleMessage::PRUNE_SUMMARY->getMessage(0, 1, 1, ConsoleMessage::FRAGMENT_ERROR->getMessage()) . PHP_EOL,
            'Forced prune must report the unreadable orphan as kept.',
        );
    }

    public function staleProviderWithoutOrphansIsDroppedOnlyWhenForced(): void
    {
        $project = $this->seedShippedOnly();

        $data = $project->lock()->read();

        $data['providers']['p/old'] = [
            'version' => '1.0.0',
            'path' => 'vendor/p/old',
        ];

        file_put_contents(
            "{$this->tempDir}/scaffold-lock.json",
            json_encode($data, JSON_THROW_ON_ERROR),
        );

        $stale = (string) file_get_contents("{$this->tempDir}/scaffold-lock.json");

        $out = new BufferedOutputWriter();

        (new PruneService())
            ->run($project->context(), $out);

        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $stale,
            'A preview must never drop providers.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::PRUNE_NO_ORPHANS->getMessage() . PHP_EOL,
            'No orphan must be reported.',
        );

        (new PruneService())->run($project->context(), new BufferedOutputWriter(), true);

        Assert::same(
            array_keys($project->lock()->read()['providers']),
            ['p/a'],
            'Forced prune must drop the stale provider.',
        );
    }

    /**
     * Returns the preview table of {@see self::seedOrphans()} followed by `$summary`.
     */
    private static function orphanTable(string $summary): string
    {
        return implode(
            PHP_EOL,
            [
                'File               Provider  Disk        With --force',
                '-----------------------------------------------------',
                '.gitignore         p/a       merged      keep',
                'edited.txt         p/a       modified    keep',
                'gone/deep/old.txt  p/a       unmodified  delete',
                'legacy.txt         p/old     unmodified  delete',
                'missing.txt        p/a       missing     drop entry',
                $summary,
                '',
            ],
        );
    }

    /**
     * Builds a project whose lock holds a shipped file, an ejected file and five orphans in every disk state.
     */
    private function seedOrphans(): InspectionProject
    {
        return (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->installed(['p/a' => '1.0.0', 'p/old' => '1.0.0'])
            ->provider('p/a', ['kept.txt' => "kept\n"])
            ->file('kept.txt', "kept\n")
            ->track('kept.txt', 'p/a')
            ->file('ej.txt', "ejected\n")
            ->track('ej.txt', 'p/a', extra: ['ejected' => true])
            ->file('gone/deep/old.txt', "old\n")
            ->track('gone/deep/old.txt', 'p/a')
            ->file('edited.txt', "provider\n")
            ->track('edited.txt', 'p/a')
            ->file('edited.txt', "mine\n")
            ->file('.gitignore', "/vendor\n/mine\n")
            ->track('.gitignore', 'p/a', 'append')
            ->track('missing.txt', 'p/a')
            ->file('legacy.txt', "legacy\n")
            ->track('legacy.txt', 'p/old');
    }

    /**
     * Builds a project whose only entry is shipped, with its lock rewritten as compact JSON.
     */
    private function seedShippedOnly(): InspectionProject
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->installed(['p/a' => '1.0.0'])
            ->provider('p/a', ['kept.txt' => "kept\n"])
            ->file('kept.txt', "kept\n")
            ->track('kept.txt', 'p/a');

        file_put_contents(
            "{$this->tempDir}/scaffold-lock.json",
            json_encode($project->lock()->read(), JSON_THROW_ON_ERROR),
        );

        return $project;
    }
}
