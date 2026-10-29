<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console;

use PHPForge\Scaffold\Console\Application;
use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Tests\Provider\ApplicationRunProvider;
use PHPForge\Scaffold\Tests\Support\{FileAssert, InspectionProject, TempDirectoryTrait};
use Symfony\Component\Console\Tester\ApplicationTester;
use Testo\Assert;
use Testo\Data\{DataProvider, DataSet};
use Testo\Lifecycle\{AfterTest, BeforeTest};
use Testo\Test;

use function array_map;
use function chdir;
use function getcwd;
use function is_dir;
use function is_string;
use function mkdir;
use function putenv;
use function str_replace;

/**
 * Functional tests for {@see Application} running the commands end to end: project root discovery, the global
 * `--working-dir` option, output formats, exit codes, lock errors and allowlist enforcement.
 *
 * {@see ApplicationRunProvider} for test case data providers.
 */
#[Test]
final class ApplicationRunTest
{
    use TempDirectoryTrait;

    /**
     * Working directory before the test, restored after it.
     */
    private string $originalCwd = '';

    /**
     * @param array<string, bool|string> $input Command input.
     */
    #[DataProvider(ApplicationRunProvider::class . '::commandsSkippingADeniedProvider')]
    public function commandSkipsAProviderMissingFromTheAllowlist(array $input, int $exitCode): void
    {
        $this
            ->project()
            ->provider('p/denied', ['d.txt' => "d\n"])
            ->file('d.txt', "x\n")
            ->track('d.txt', 'p/denied');

        $tester = $this->runApplication($input);

        Assert::same(
            $tester->getStatusCode(),
            $exitCode,
            "Exit code must be '{$exitCode}'.",
        );
        Assert::same(
            $tester->getErrorOutput(),
            ConsoleMessage::COMMAND_PROVIDER_NOT_ALLOWED->getMessage('d.txt', 'p/denied') . PHP_EOL,
            "Error output must hold only the 'p/denied' rejection.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/d.txt",
            "x\n",
            "File 'd.txt' must never be written.",
        );
    }

    /**
     * @param array<string, bool|string> $input Command input.
     */
    #[DataProvider(ApplicationRunProvider::class . '::commandsReadingTheLock')]
    public function corruptLockIsReportedAsScaffoldError(array $input): void
    {
        $this->project()->file('scaffold-lock.json', '{not json');

        $tester = $this->runApplication($input);

        Assert::same(
            $tester->getStatusCode(),
            1,
            "Exit code must be '1'.",
        );
        Assert::same(
            $tester->getErrorOutput(),
            ConsoleMessage::SCAFFOLD_PREFIXED->getMessage(
                Message::LOCK_INVALID_JSON->getMessage((new LockFile($this->tempDir))->getPath(), 'Syntax error'),
            )
            . PHP_EOL,
            'Error output must hold only the lock error.',
        );
        Assert::same(
            $tester->getDisplay(),
            '',
            'Output must stay empty.',
        );
    }

    /**
     * @param array<string, bool|int|string> $input Command input.
     */
    #[DataSet([['command' => 'diff']], 'no target')]
    #[DataSet([['command' => 'diff', 'file' => 'a.txt', '--all' => true]], 'both targets')]
    #[DataSet([['command' => 'diff', '--all' => 0]], 'falsy all option')]
    public function diffRequiresExactlyOneOfFileOrAll(array $input): void
    {
        $this
            ->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a');

        $tester = $this->runApplication($input);

        Assert::same(
            $tester->getStatusCode(),
            2,
            "Exit code must be '2'.",
        );
        Assert::same(
            $tester->getErrorOutput(),
            ConsoleMessage::DIFF_TARGET_AMBIGUOUS->getMessage() . PHP_EOL,
            'Error output must hold only the usage message.',
        );
    }

    public function diffWithExitCodeAndAll(): void
    {
        $this
            ->project()
            ->provider('p/a', ['a.txt' => "a\n", 'b.txt' => "b\n"])
            ->file('a.txt', "a\n")
            ->file('b.txt', "b\n")
            ->track('a.txt', 'p/a')
            ->track('b.txt', 'p/a');

        Assert::same(
            $this->runApplication(['command' => 'diff', '--all' => true, '--exit-code' => true])->getStatusCode(),
            0,
            "Exit code of a clean project must be '0'.",
        );

        $this->project()->stub('p/a', 'b.txt', "b2\n");

        $tester = $this->runApplication(['command' => 'diff', '--all' => true, '--exit-code' => true]);

        Assert::same(
            $tester->getStatusCode(),
            1,
            "Exit code of a pending change with '--exit-code' must be '1'.",
        );
        Assert::string($tester->getDisplay())->startsWith(
            ConsoleMessage::DIFF_NO_CHANGES->getMessage('a.txt') . PHP_EOL
            . ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('b.txt', 'replace', 'p/a', ''),
            'Files must be listed in lock order.',
        );
    }

    public function missingComposerJsonIsReportedOnStderr(): void
    {
        putenv('COMPOSER=scaffold-application-run-missing.json');

        try {
            $tester = $this->runApplication(['command' => 'status']);
        } finally {
            putenv('COMPOSER');
        }

        Assert::same(
            $tester->getStatusCode(),
            1,
            "Exit code must be '1'.",
        );
        Assert::same(
            $tester->getErrorOutput(),
            ConsoleMessage::COMMAND_MANIFEST_NOT_FOUND->getMessage(
                'scaffold-application-run-missing.json',
                $this->tempDir,
            )
            . PHP_EOL,
            'Error must name the search start.',
        );
        Assert::same(
            $tester->getDisplay(),
            '',
            'Output must stay empty.',
        );
    }

    public function missingWorkingDirectoryFails(): void
    {
        $tester = $this->runApplication(['command' => 'status', '-d' => "{$this->tempDir}/nope"]);

        Assert::same(
            $tester->getStatusCode(),
            1,
            "Exit code must be '1'.",
        );
        Assert::same(
            $tester->getErrorOutput(),
            ConsoleMessage::COMMAND_WORKING_DIR_NOT_FOUND->getMessage("{$this->tempDir}/nope") . PHP_EOL,
            'Error output must hold only the missing directory error.',
        );
    }

    public function reapplyDryRunWritesNothing(): void
    {
        $this
            ->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->stub('p/a', 'a.txt', "a2\n");

        $tester = $this->runApplication(['command' => 'reapply', '--dry-run' => true, '--exit-code' => true]);

        Assert::same(
            $tester->getStatusCode(),
            1,
            "Exit code of a pending write with '--exit-code' must be '1'.",
        );
        Assert::string(
            $tester->getDisplay())->contains('+a2',
            "Diff must print the '+a2' line.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "a\n",
            'Nothing may be written.',
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
     * Saves the working directory, writes the project manifest and enters the project root.
     */
    #[BeforeTest(priority: -10)]
    public function setUpProject(): void
    {
        $this->originalCwd = (string) getcwd();

        $this->project()->composer(['p/a']);

        chdir($this->tempDir);
    }

    /**
     * @param string $cwd Working directory of the run.
     * @param array<string, bool|string> $input Command input.
     */
    #[DataProvider(ApplicationRunProvider::class . '::projectRootStarts')]
    public function statusFindsTheProjectRoot(string $cwd, array $input): void
    {
        $this
            ->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a');

        mkdir("{$this->tempDir}/src/deep", 0o777, true);
        chdir(str_replace('{root}', $this->tempDir, $cwd));

        $tester = $this->runApplication(
            array_map(
                fn(bool|string $value): bool|string => is_string($value)
                    ? str_replace('{root}', $this->tempDir, $value)
                    : $value,
                $input,
            ),
        );

        Assert::same(
            $tester->getStatusCode(),
            0,
            "Exit code must be '0'.",
        );
        Assert::string($tester->getDisplay())->endsWith(
            ConsoleMessage::STATUS_SUMMARY->getMessage(
                1,
                ConsoleMessage::FRAGMENT_FILE->getMessage(),
                ConsoleMessage::FRAGMENT_COUNT->getMessage(1, 'synced'),
            )
            . PHP_EOL,
            'Summary must report the one synced file of the root.',
        );
    }

    public function statusJsonFormatAndExitCode(): void
    {
        $this
            ->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->file('a.txt', "mine\n");

        $json = $this->runApplication(['command' => 'status', '--format' => 'json']);
        $exit = $this->runApplication(['command' => 'status', '--exit-code' => true]);
        $invalid = $this->runApplication(['command' => 'status', '--format' => 'xml']);

        Assert::same(
            $json->getStatusCode(),
            0,
            "Exit code of JSON without '--exit-code' must be '0'.",
        );
        Assert::string($json->getDisplay())->contains(
            "\"a.txt\": {\n            \"provider\": \"p/a\",\n            \"mode\": \"replace\",\n"
            . '            "status": "modified"',
            "JSON must carry the 'modified' state.",
        );
        Assert::same(
            $exit->getStatusCode(),
            1,
            "Exit code of a modified file with '--exit-code' must be '1'.",
        );
        Assert::same(
            $invalid->getStatusCode(),
            2,
            "Exit code of an unknown format must be '2'.",
        );
        Assert::same(
            $invalid->getErrorOutput(),
            ConsoleMessage::STATUS_FORMAT_UNKNOWN->getMessage('xml') . PHP_EOL,
            'Error output must hold only the usage message.',
        );
    }

    /**
     * Returns the builder of the temporary project.
     */
    private function project(): InspectionProject
    {
        return new InspectionProject($this->tempDir);
    }

    /**
     * Runs the application with the error output captured separately.
     *
     * @param array<string, bool|int|string> $input Command input.
     */
    private function runApplication(array $input): ApplicationTester
    {
        $application = new Application();

        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);

        $tester->run($input, ['capture_stderr_separately' => true, 'decorated' => false]);

        return $tester;
    }
}
