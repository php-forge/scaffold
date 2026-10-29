<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console\Command;

use PHPForge\Scaffold\Console\Command\ReapplyCommand;
use PHPForge\Scaffold\Tests\Provider\ReapplyCommandProvider;
use PHPForge\Scaffold\Tests\Support\{FileAssert, InspectionProject, TempDirectoryTrait};
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Lifecycle\{AfterTest, BeforeTest};
use Testo\Test;

use function chdir;
use function getcwd;
use function is_dir;

/**
 * Functional tests for the Symfony Console {@see ReapplyCommand} wiring the `file` argument and the `--force`,
 * `--provider` and `--dry-run` options to the reapply service.
 *
 * {@see ReapplyCommandProvider} for test case data providers.
 */
#[Test]
final class ReapplyCommandTest
{
    use TempDirectoryTrait;

    /**
     * Working directory before the test, restored after it.
     */
    private string $originalCwd = '';

    /**
     * Ensures the input decides the exit code and whether the user-modified file is overwritten with its stub.
     *
     * @param array<string, bool|string> $input Command input.
     */
    #[DataProvider(ReapplyCommandProvider::class . '::inputs')]
    public function inputDecidesWhatIsReapplied(array $input, int $exitCode, string $content): void
    {
        $tester = new CommandTester(new ReapplyCommand());

        Assert::same(
            $tester->execute($input),
            $exitCode,
            "Exit code must be '{$exitCode}'.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/config/params.php",
            $content,
            "Content of 'config/params.php' must match.",
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
     * Saves the working directory, seeds a tracked file the user modified and enters the project root.
     */
    #[BeforeTest(priority: -10)]
    public function setUpProject(): void
    {
        $this->originalCwd = (string) getcwd();

        (new InspectionProject($this->tempDir))
            ->composer(['pkg/name'])
            ->provider('pkg/name', ['config/params.php' => "original\n"])
            ->file('config/params.php', "original\n")
            ->track('config/params.php', 'pkg/name')
            ->file('config/params.php', "user-edited\n");

        chdir($this->tempDir);
    }
}
