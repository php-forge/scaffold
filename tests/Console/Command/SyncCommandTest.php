<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console\Command;

use PHPForge\Scaffold\Console\Command\SyncCommand;
use PHPForge\Scaffold\Tests\Support\{FileAssert, InspectionProject, TempDirectoryTrait};
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Lifecycle\{AfterTest, BeforeTest};
use Testo\Test;

use function chdir;
use function file_put_contents;
use function getcwd;
use function is_dir;

/**
 * Functional tests for the Symfony Console {@see SyncCommand} wiring its options to the sync service.
 */
#[Test]
final class SyncCommandTest
{
    use TempDirectoryTrait;

    /**
     * Working directory before the test, restored after it.
     */
    private string $originalCwd = '';

    /**
     * Ensures `--dry-run --exit-code` exits with `1` on a pending change and writes neither the file nor the lock.
     */
    public function dryRunWithExitCodeReportsPendingChangesWithoutWriting(): void
    {
        $tester = new CommandTester(new SyncCommand());

        Assert::same(
            $tester->execute(['--dry-run' => true, '--exit-code' => true]),
            1,
            "Exit code must be '1'.",
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/a.txt",
            "Dry run must not write 'a.txt'.",
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/scaffold-lock.json",
            'Dry run must not write the lock.',
        );
    }

    /**
     * Ensures only `--force` overwrites a user edit of a synced file with its stub.
     *
     * @param array<string, bool> $options Command options.
     */
    #[DataSet([[], "mine\n"], 'without force')]
    #[DataSet([['--force' => true], "a\n"], 'with force')]
    public function forceOptionDecidesWhetherUserEditsAreOverwritten(array $options, string $content): void
    {
        (new CommandTester(new SyncCommand()))->execute([]);

        file_put_contents("{$this->tempDir}/a.txt", "mine\n");

        Assert::same(
            (new CommandTester(new SyncCommand()))->execute($options),
            0,
            "Exit code must be '0'.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            $content,
            "Content of 'a.txt' must match.",
        );
    }

    /**
     * Restores the working directory of the runner.
     */
    #[AfterTest]
    public function restoreWorkingDirectory(): void
    {
        if ($this->originalCwd !== '' && is_dir($this->originalCwd)) {
            chdir($this->originalCwd);
        }
    }

    /**
     * Saves the working directory, seeds an installed provider and enters the project root.
     */
    #[BeforeTest(priority: -10)]
    public function setUpProject(): void
    {
        $this->originalCwd = (string) getcwd();

        (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->installed(['p/a' => '1.0.0'])
            ->provider('p/a', ['a.txt' => "a\n"]);

        chdir($this->tempDir);
    }

    /**
     * Ensures a sync writes the provider files and creates the lock.
     */
    public function syncWritesTheProviderFilesAndTheLock(): void
    {
        $tester = new CommandTester(new SyncCommand());

        Assert::same(
            $tester->execute([]),
            0,
            "Exit code must be '0'.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "a\n",
            "File 'a.txt' must be written.",
        );
        FileAssert::fileExists(
            "{$this->tempDir}/scaffold-lock.json",
            'Lock must be created.',
        );
    }
}
