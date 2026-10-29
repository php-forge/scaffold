<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console\Command;

use PHPForge\Scaffold\Console\Command\ProvidersCommand;
use PHPForge\Scaffold\Services\ProvidersService;
use PHPForge\Scaffold\Tests\Support\{BufferedOutputWriter, InspectionProject, TempDirectoryTrait};
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Lifecycle\{AfterTest, BeforeTest};
use Testo\Test;

use function chdir;
use function getcwd;
use function is_dir;
use function mkdir;

/**
 * Functional tests for the Symfony Console {@see ProvidersCommand} running the providers service on the project root.
 */
#[Test]
final class ProvidersCommandTest
{
    use TempDirectoryTrait;

    /**
     * Working directory before the test, restored after it.
     */
    private string $originalCwd = '';

    /**
     * Ensures the command prints the provider summary of the project root and exits with `0`.
     */
    public function printsTheProviderSummaryOfTheProjectRoot(): void
    {
        $expected = new BufferedOutputWriter();

        (new ProvidersService())->run($this->tempDir, $expected);

        $tester = new CommandTester(new ProvidersCommand());

        $exitCode = $tester->execute([]);

        Assert::same(
            $exitCode,
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            $tester->getDisplay(),
            $expected->stdoutBuffer,
            'Output must be the provider summary of the project root.',
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
     * Saves the working directory, seeds files of two providers and enters a directory below the project root.
     */
    #[BeforeTest(priority: -10)]
    public function setUpProject(): void
    {
        $this->originalCwd = (string) getcwd();

        (new InspectionProject($this->tempDir))
            ->composer(['pkg/a', 'pkg/b'])
            ->provider('pkg/a', ['a.txt' => "a\n", 'b.txt' => "b\n"])
            ->provider('pkg/b', ['c.txt' => "c\n"])
            ->file('a.txt', "a\n")
            ->file('b.txt', "b\n")
            ->file('c.txt', "c\n")
            ->track('a.txt', 'pkg/a')
            ->track('b.txt', 'pkg/a')
            ->track('c.txt', 'pkg/b', 'preserve');

        mkdir("{$this->tempDir}/src");
        chdir("{$this->tempDir}/src");
    }
}
