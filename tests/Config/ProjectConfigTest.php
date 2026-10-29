<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Config;

use PHPForge\Scaffold\Config\ProjectConfig;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Tests\Support\TempDirectoryTrait;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

use function file_put_contents;

/**
 * Unit tests for {@see ProjectConfig} reading `extra.scaffold` from the root package and the project `composer.json`.
 */
#[Test]
#[Covers(ProjectConfig::class)]
final class ProjectConfigTest
{
    use TempDirectoryTrait;

    /**
     * Ensures only an explicit `false` disables automatic scaffolding.
     *
     * @param array<string, mixed> $extra Root package `extra` block.
     */
    #[DataSet([[], true], 'missing block')]
    #[DataSet([['scaffold' => ['auto' => 0]], true], 'integer zero')]
    #[DataSet([['scaffold' => ['auto' => false]], false], 'explicit false')]
    public function autoIsDisabledOnlyByExplicitFalse(array $extra, bool $expected): void
    {
        Assert::same(
            ProjectConfig::fromExtra($extra)->auto,
            $expected,
            "Only 'false' may disable auto.",
        );
    }

    /**
     * Ensures the allowed packages are read from `composer.json` in their declared order.
     */
    public function fromComposerJsonReadsAllowedPackages(): void
    {
        file_put_contents(
            "{$this->tempDir}/composer.json",
            '{"extra": {"scaffold": {"allowed-packages": ["p/a", "p/b"]}}}',
        );

        $config = ProjectConfig::fromComposerJson("{$this->tempDir}/composer.json");

        Assert::same(
            $config->allowedPackages,
            ['p/a', 'p/b'],
            'Allowed packages must keep their order.',
        );
        Assert::true(
            $config->isAllowed('p/b'),
            'Listed package must be allowed.',
        );
        Assert::false(
            $config->isAllowed('p/c'),
            'Unlisted package must not be allowed.',
        );
    }

    /**
     * Ensures only a non-empty string `config.vendor-dir` is kept as written.
     */
    #[DataSet(['{"config": {"vendor-dir": "lib/vendor"}}', 'lib/vendor'], 'non-empty string')]
    #[DataSet(['{"config": {"vendor-dir": ""}}', null], 'empty string')]
    #[DataSet(['{"config": "x"}', null], 'non-object config')]
    public function fromComposerJsonReadsTheVendorDirSetting(string $manifest, string|null $expected): void
    {
        file_put_contents(
            "{$this->tempDir}/composer.json",
            $manifest,
        );

        Assert::same(
            ProjectConfig::fromComposerJson("{$this->tempDir}/composer.json")->vendorDir,
            $expected,
            'Vendor directory setting must match.',
        );
    }

    /**
     * Ensures a manifest without a usable `extra` block allows no package.
     */
    public function fromComposerJsonWithoutExtraAllowsNothing(): void
    {
        file_put_contents(
            "{$this->tempDir}/composer.json",
            '{"name": "a/b", "extra": "x"}',
        );

        Assert::same(
            ProjectConfig::fromComposerJson("{$this->tempDir}/composer.json")->allowedPackages,
            [],
            'Allowlist must be empty.',
        );
    }

    /**
     * Ensures non-string allowlist entries are dropped and counted.
     */
    public function nonStringEntriesAreCountedAndIgnored(): void
    {
        $config = ProjectConfig::fromExtra(['scaffold' => ['allowed-packages' => ['p/a', 42, null]]]);

        Assert::same(
            $config->allowedPackages,
            ['p/a'],
            'Only string entries are kept.',
        );
        Assert::same(
            $config->ignoredEntries,
            2,
            'Every non-string entry is counted.',
        );
    }

    /**
     * Ensures an unreadable `composer.json` throws with the PHP warning as the reason.
     */
    public function throwRuntimeExceptionWhenComposerJsonCannotBeRead(): void
    {
        $path = "{$this->tempDir}/composer.json";

        file_put_contents(
            $path,
            '{}',
        );

        $this->restrictFileAccess($path, 0o000);

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    Message::CONFIG_MANIFEST_UNREADABLE->getMessage($path),
                    "file_get_contents({$path}): Failed to open stream: Permission denied",
                ),
            );

        ProjectConfig::fromComposerJson($path);
    }

    /**
     * Ensures an invalid JSON manifest throws with the decoder error.
     */
    public function throwRuntimeExceptionWhenComposerJsonIsInvalidJson(): void
    {
        file_put_contents("{$this->tempDir}/composer.json", '{not json');

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::CONFIG_MANIFEST_INVALID_JSON->getMessage("{$this->tempDir}/composer.json", 'Syntax error'),
            )
            ->withCode(0);

        ProjectConfig::fromComposerJson("{$this->tempDir}/composer.json");
    }

    /**
     * Ensures a missing `composer.json` throws.
     */
    public function throwRuntimeExceptionWhenComposerJsonIsMissing(): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::CONFIG_MANIFEST_NOT_FOUND->getMessage("{$this->tempDir}/composer.json"),
            );

        ProjectConfig::fromComposerJson("{$this->tempDir}/composer.json");
    }

    /**
     * Ensures a manifest whose root is not a JSON object throws.
     */
    public function throwRuntimeExceptionWhenComposerJsonIsNotAnObject(): void
    {
        file_put_contents("{$this->tempDir}/composer.json", '"text"');

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::CONFIG_MANIFEST_NOT_OBJECT->getMessage("{$this->tempDir}/composer.json"),
            );

        ProjectConfig::fromComposerJson("{$this->tempDir}/composer.json");
    }
}
