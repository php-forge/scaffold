<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console\Command;

use PHPForge\Scaffold\Console\Command\EjectCommand;
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Lifecycle\{AfterTest, BeforeTest};
use Testo\Test;

use function chdir;
use function getcwd;
use function is_dir;

/**
 * Functional tests for the Symfony Console {@see EjectCommand} wiring the `file` argument and the `--yes` option to
 * the eject service.
 */
#[Test]
final class EjectCommandTest
{
    use TempDirectoryTrait;

    /**
     * Working directory before the test, restored after it.
     */
    private string $originalCwd = '';

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
     * Saves the working directory, seeds a tracked file and enters the project root.
     */
    #[BeforeTest(priority: -10)]
    public function setUpProject(): void
    {
        $this->originalCwd = (string) getcwd();

        (new InspectionProject($this->tempDir))
            ->composer(['pkg/name'])
            ->provider('pkg/name', ['config/params.php' => "<?php\n"])
            ->file('config/params.php', "<?php\n")
            ->track('config/params.php', 'pkg/name');

        chdir($this->tempDir);
    }

    /**
     * Ensures only `--yes` marks the named lock entry as ejected; without it the lock is left untouched.
     *
     * @param array<string, bool> $options Command options.
     */
    #[DataSet([[], false], 'without yes')]
    #[DataSet([['--yes' => true], true], 'with yes')]
    public function yesOptionConfirmsTheEjection(array $options, bool $ejected): void
    {
        $tester = new CommandTester(new EjectCommand());

        $exitCode = $tester->execute(['file' => 'config/params.php', ...$options]);

        Assert::same(
            $exitCode,
            0,
            "Exit code must be '0'.",
        );

        $entry = (new InspectionProject($this->tempDir))->lock()->read()['files']['config/params.php'] ?? null;

        Assert::notNull(
            $entry,
            "Lock entry of 'config/params.php' must be kept.",
        );
        Assert::same(
            $entry['ejected'] ?? false,
            $ejected,
            "Lock entry must be marked 'ejected' only with '--yes'.",
        );
    }
}
