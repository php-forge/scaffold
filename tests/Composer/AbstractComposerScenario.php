<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Composer;

use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Tests\Support\{ProcessResult, RealComposer, TempDirectoryTrait};
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;

use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function str_contains;

/**
 * Provides a temporary consumer project that installs this working tree and the provider fixture with the real
 * `composer` binary.
 *
 * The {@see BeforeTest} hooks create the project; {@see self::writeComposerJson()} and {@see self::composer()} skip
 * the test from its body when Composer is not available.
 *
 * @phpstan-import-type LockEntry from LockFile
 */
abstract class AbstractComposerScenario
{
    use TempDirectoryTrait;

    /**
     * Absolute path of the consumer project.
     */
    protected string $project = '';
    /**
     * Absolute path of the temporary directory that holds the consumer project, the plugin snapshot and the
     * `COMPOSER_HOME` of the test.
     */
    protected string $workspace = '';

    /**
     * Creates the consumer project directory inside the temporary directory, after the temporary directory hook.
     */
    #[BeforeTest(priority: -1)]
    public function createProject(): void
    {
        $this->workspace = $this->tempDir;
        $this->project = "{$this->tempDir}/project";

        mkdir($this->project);
    }

    /**
     * Asserts that `$output` contains `$needle`, showing the whole output when it does not.
     *
     * @param string $output Captured output of a process.
     * @param string $needle Expected text.
     * @param string $message Failure message.
     */
    protected static function assertOutputContains(string $output, string $needle, string $message): void
    {
        Assert::same(
            str_contains($output, $needle)
                ? $needle
                : $output,
            $needle,
            $message,
        );
    }

    /**
     * Asserts that `$output` does not contain `$needle`, showing the whole output when it does.
     *
     * @param string $output Captured output of a process.
     * @param string $needle Unexpected text.
     * @param string $message Failure message.
     */
    protected static function assertOutputLacks(string $output, string $needle, string $message): void
    {
        Assert::same(
            str_contains($output, $needle)
                ? $output
                : '',
            '',
            $message
        );
    }

    /**
     * Runs Composer in `$cwd`, the project by default, and returns its result.
     *
     * @param list<string> $arguments Composer arguments, such as `['install']`.
     * @param string|null $cwd Working directory, or `null` for the project.
     */
    protected function composer(array $arguments, string|null $cwd = null): ProcessResult
    {
        return RealComposer::run($arguments, $cwd ?? $this->project, "{$this->workspace}/home");
    }

    /**
     * Returns the decoded `scaffold-lock.json` of the project.
     *
     * @return array{providers: array<string, mixed>, files: array<array-key, LockEntry>}
     */
    protected function lock(): array
    {
        return (new LockFile($this->project))->read();
    }

    /**
     * Returns the absolute path of a project file.
     */
    protected function path(string $relative): string
    {
        return "{$this->project}/{$relative}";
    }

    /**
     * Returns the repositories that install the plugin snapshot of this test and the provider fixture
     * `$providerVersion`, writing the snapshot on first use.
     *
     * @param string $providerVersion Provider fixture release: `1.0.0` or `2.0.0`.
     *
     * @return list<array<string, mixed>>
     */
    protected function repositories(string $providerVersion = '1.0.0'): array
    {
        $plugin = "{$this->workspace}/plugin";

        if (!is_dir($plugin)) {
            RealComposer::snapshot($plugin);
        }

        return RealComposer::repositories($plugin, $providerVersion);
    }

    /**
     * Runs `vendor/bin/scaffold` of the project and returns its result.
     *
     * @param list<string> $arguments Command name followed by its arguments and options.
     * @param string $binDir Composer bin directory relative to the project.
     */
    protected function scaffold(array $arguments, string $binDir = 'vendor/bin'): ProcessResult
    {
        return RealComposer::runPhp(
            ["{$this->project}/{$binDir}/scaffold", ...$arguments],
            $this->project,
        );
    }

    /**
     * Writes the project `composer.json` requiring the plugin and the provider fixture, skipping the test when Composer
     * is not available.
     *
     * @param array<string, mixed> $scaffold Settings merged into `extra.scaffold`.
     * @param array<string, mixed> $config Settings merged into `config`.
     * @param string $providerVersion Provider fixture release: `1.0.0` or `2.0.0`.
     */
    protected function writeComposerJson(
        array $scaffold = [],
        array $config = [],
        string $providerVersion = '1.0.0',
    ): void {
        RealComposer::requireCommand();

        file_put_contents(
            "{$this->project}/composer.json",
            json_encode(
                [
                    'name' => 'fixture/consumer',
                    'type' => 'project',
                    'repositories' => $this->repositories($providerVersion),
                    'require' => [
                        ...RealComposer::requirements(),
                        'fixture/provider' => '*',
                    ],
                    'config' => [
                        'allow-plugins' => ['php-forge/scaffold' => true],
                        ...$config,
                    ],
                    'extra' => ['scaffold' => ['allowed-packages' => ['fixture/provider'], ...$scaffold]],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n",
        );
    }
}
