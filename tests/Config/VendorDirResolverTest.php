<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Config;

use PHPForge\Scaffold\Config\{ProjectContext, VendorDirResolver};
use PHPForge\Scaffold\Tests\Provider\VendorDirResolverProvider;
use PHPForge\Scaffold\Tests\Support\TempDirectoryTrait;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\{AfterTest, BeforeTest};
use Testo\Test;

use function file_put_contents;
use function getenv;
use function json_encode;
use function putenv;
use function str_replace;

/**
 * Unit tests for {@see VendorDirResolver} covering environment variable, `composer.json`, and default fallback
 * resolution paths.
 *
 * {@see VendorDirResolverProvider} for test case data providers.
 */
#[Test]
#[Covers(VendorDirResolver::class)]
#[Covers(ProjectContext::class)]
final class VendorDirResolverTest
{
    use TempDirectoryTrait;

    /**
     * Value of `COMPOSER_VENDOR_DIR` before the test, or `false` when it was unset.
     */
    private string|false $originalEnv = false;

    /**
     * Ensures `COMPOSER` names the manifest that holds `config.vendor-dir`.
     */
    public function composerEnvNamesTheManifestThatHoldsTheVendorDir(): void
    {
        file_put_contents(
            "{$this->tempDir}/composer.json",
            json_encode(['config' => ['vendor-dir' => 'wrong']]),
        );
        file_put_contents(
            "{$this->tempDir}/app.json",
            json_encode(['config' => ['vendor-dir' => 'lib/vendor']]),
        );

        putenv('COMPOSER=app.json');

        try {
            $vendorDir = ProjectContext::create($this->tempDir)->vendorDir;
            $manifestName = VendorDirResolver::manifestName();
        } finally {
            putenv('COMPOSER');
        }

        Assert::same(
            $vendorDir,
            "{$this->tempDir}/lib/vendor",
            "The manifest named by 'COMPOSER' must be read.",
        );
        Assert::same(
            $manifestName,
            'app.json',
            "Manifest name must follow 'COMPOSER'.",
        );
        Assert::same(
            VendorDirResolver::manifestName(),
            'composer.json',
            "Manifest name must default to 'composer.json'.",
        );
    }

    /**
     * Ensures the default vendor directory drops the trailing separator of the project root.
     */
    public function defaultFallbackStripsTrailingSeparatorFromProjectRoot(): void
    {
        Assert::same(
            VendorDirResolver::resolve("{$this->tempDir}/"),
            "{$this->tempDir}/vendor",
            "Default vendor directory must not hold '//vendor'.",
        );
    }

    /**
     * Ensures `COMPOSER_VENDOR_DIR` wins over `config.vendor-dir`.
     */
    public function environmentTakesPriorityOverComposerJson(): void
    {
        file_put_contents(
            "{$this->tempDir}/composer.json",
            json_encode(['config' => ['vendor-dir' => 'ignored']]),
        );
        putenv('COMPOSER_VENDOR_DIR=/absolute/env-vendor');

        Assert::same(
            ProjectContext::create($this->tempDir)->vendorDir,
            '/absolute/env-vendor',
            "'COMPOSER_VENDOR_DIR' must win over 'config.vendor-dir'.",
        );
    }

    /**
     * Ensures each `COMPOSER_VENDOR_DIR` value resolves to the expected absolute path.
     */
    #[DataProvider(VendorDirResolverProvider::class . '::environmentValues')]
    public function resolvesEnvironmentValue(string $value, string $expected): void
    {
        putenv("COMPOSER_VENDOR_DIR={$value}");

        Assert::same(
            VendorDirResolver::resolve($this->tempDir),
            str_replace('{root}', $this->tempDir, $expected),
            "Resolved path of 'COMPOSER_VENDOR_DIR' must match.",
        );
    }

    /**
     * Ensures each `config.vendor-dir` setting resolves to the expected absolute path.
     *
     * @param array<string, mixed> $manifest Content of `composer.json`.
     * @param string $rootSuffix Suffix appended to the project root, such as a trailing separator.
     */
    #[DataProvider(VendorDirResolverProvider::class . '::manifestSettings')]
    public function resolvesManifestSetting(array $manifest, string $rootSuffix, string $expected): void
    {
        file_put_contents(
            "{$this->tempDir}/composer.json",
            json_encode($manifest),
        );

        Assert::same(
            ProjectContext::create($this->tempDir . $rootSuffix)->vendorDir,
            str_replace('{root}', $this->tempDir, $expected),
            "Resolved path of 'config.vendor-dir' must match.",
        );
    }

    /**
     * Restores `COMPOSER_VENDOR_DIR` to its value before the test.
     */
    #[AfterTest]
    public function restoreEnvironment(): void
    {
        putenv($this->originalEnv === false ? 'COMPOSER_VENDOR_DIR' : "COMPOSER_VENDOR_DIR={$this->originalEnv}");
    }

    /**
     * Saves and clears `COMPOSER_VENDOR_DIR`, so the environment of the runner never leaks into a test.
     */
    #[BeforeTest]
    public function saveEnvironment(): void
    {
        $this->originalEnv = getenv('COMPOSER_VENDOR_DIR');

        putenv('COMPOSER_VENDOR_DIR');
    }
}
