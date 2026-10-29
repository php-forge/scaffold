<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console\Command;

use PHPForge\Scaffold\Console\Command\{AbstractScaffoldCommand, StatusCommand};
use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Tests\Support\TempDirectoryTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\{AfterTest, BeforeTest};
use Testo\Test;

use function chdir;
use function getcwd;
use function is_dir;
use function mkdir;
use function rmdir;

/**
 * Functional tests for the Symfony Console {@see StatusCommand} covering the project root resolution of
 * {@see AbstractScaffoldCommand} when the current working directory is gone.
 */
#[Test]
final class StatusCommandTest
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
     * Ensures a removed current working directory fails with the unresolvable working directory error.
     */
    public function returnsFailureWhenTheWorkingDirectoryIsGone(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('Windows does not remove the current working directory.');
        }

        $removed = "{$this->tempDir}/removed";

        mkdir($removed);
        chdir($removed);
        rmdir($removed);

        $tester = new CommandTester(new StatusCommand());

        Assert::same(
            $tester->execute([]),
            1,
            "Exit code must be '1'.",
        );
        Assert::same(
            $tester->getDisplay(),
            Message::COMMAND_CWD_UNRESOLVABLE->getMessage() . PHP_EOL,
            'Output must hold only the unresolvable working directory error.',
        );
    }

    /**
     * Saves the working directory of the runner.
     */
    #[BeforeTest]
    public function saveWorkingDirectory(): void
    {
        $this->originalCwd = (string) getcwd();
    }
}
