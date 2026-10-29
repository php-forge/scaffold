<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console\Command;

use PHPForge\Scaffold\Console\Command\PruneCommand;
use PHPForge\Scaffold\Tests\Provider\PruneCommandProvider;
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Lifecycle\{AfterTest, BeforeTest};
use Testo\Test;

use function chdir;
use function getcwd;
use function is_dir;
use function is_file;

/**
 * Functional tests for the Symfony Console {@see PruneCommand} wiring its options to the prune service.
 *
 * {@see PruneCommandProvider} for test case data providers.
 */
#[Test]
final class PruneCommandTest
{
    use TempDirectoryTrait;

    /**
     * Working directory before the test, restored after it.
     */
    private string $originalCwd = '';

    /**
     * Ensures `--delete-modified` turns the previewed action of a modified orphan into a deletion.
     */
    public function deleteModifiedPreviewShowsTheDestructiveAction(): void
    {
        $tester = new CommandTester(new PruneCommand());

        $tester->execute(['--delete-modified' => true]);

        Assert::string($tester->getDisplay())->contains(
            'edited.txt  p/a       modified    delete (modified)',
            "Preview must announce 'delete (modified)' for 'edited.txt'.",
        );
    }

    /**
     * Ensures `--force`, `--delete-modified` and `--exit-code` decide the exit code and which orphans are deleted.
     *
     * @param array<string, bool> $options Command options.
     * @param array{'old.txt': bool, 'edited.txt': bool} $kept Whether each orphan is kept.
     */
    #[DataProvider(PruneCommandProvider::class . '::options')]
    public function optionsDecideWhatIsPruned(array $options, int $exitCode, array $kept): void
    {
        $tester = new CommandTester(new PruneCommand());

        Assert::same(
            $tester->execute($options),
            $exitCode,
            "Exit code must be '{$exitCode}'.",
        );
        Assert::same(
            [
                'old.txt' => is_file("{$this->tempDir}/old.txt"),
                'edited.txt' => is_file("{$this->tempDir}/edited.txt"),
            ],
            $kept,
            'Kept orphans must match.',
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
     * Saves the working directory, seeds an unmodified and a modified orphan and enters the project root.
     */
    #[BeforeTest(priority: -10)]
    public function setUpProject(): void
    {
        $this->originalCwd = (string) getcwd();

        (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->installed(['p/a' => '1.0.0'])
            ->provider('p/a', ['kept.txt' => "kept\n"])
            ->file('old.txt', "old\n")
            ->track('old.txt', 'p/a')
            ->file('edited.txt', "provider\n")
            ->track('edited.txt', 'p/a')
            ->file('edited.txt', "mine\n");

        chdir($this->tempDir);
    }
}
