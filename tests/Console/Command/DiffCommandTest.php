<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console\Command;

use PHPForge\Scaffold\Console\Command\DiffCommand;
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Lifecycle\{AfterTest, BeforeTest};
use Testo\Test;

use function chdir;
use function getcwd;
use function is_dir;
use function str_contains;

/**
 * Functional tests for the Symfony Console {@see DiffCommand} wiring the `file` argument and the output decoration
 * to the diff service.
 */
#[Test]
final class DiffCommandTest
{
    use TempDirectoryTrait;

    /**
     * Working directory before the test, restored after it.
     */
    private string $originalCwd = '';

    /**
     * Ensures the diff of the named file is colored only when the output is decorated.
     */
    #[DataSet([true], 'decorated')]
    #[DataSet([false], 'not decorated')]
    public function colorsFollowTheOutputDecoration(bool $decorated): void
    {
        $tester = new CommandTester(new DiffCommand());

        $exitCode = $tester->execute(['file' => 'config/params.php'], ['decorated' => $decorated]);

        Assert::same(
            $exitCode,
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            str_contains($tester->getDisplay(), "\e["),
            $decorated,
            'Output must hold ANSI codes only when decorated.',
        );
        Assert::string($tester->getDisplay())->ignoringAnsi()->contains(
            "-return ['a' => 2];\n+return ['a' => 1];",
            "Output must hold the diff of 'config/params.php'.",
        )->notContains(
            'config/other.php',
            "Output must not hold the diff of 'config/other.php'.",
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
     * Saves the working directory, seeds two tracked files that diverge from their stubs and enters the project root.
     */
    #[BeforeTest(priority: -10)]
    public function setUpProject(): void
    {
        $this->originalCwd = (string) getcwd();

        (new InspectionProject($this->tempDir))
            ->composer(['pkg/name'])
            ->provider('pkg/name', ['config/params.php' => "return ['a' => 1];\n", 'config/other.php' => "b\n"])
            ->file('config/params.php', "return ['a' => 2];\n")
            ->file('config/other.php', "c\n")
            ->track('config/params.php', 'pkg/name')
            ->track('config/other.php', 'pkg/name');

        chdir($this->tempDir);
    }
}
