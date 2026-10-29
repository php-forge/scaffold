<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Config;

use PHPForge\Scaffold\Config\{InstalledPackages, ProjectConfig, ProjectContext};
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function array_keys;
use function mkdir;
use function realpath;
use function symlink;

/**
 * Unit tests for {@see ProjectContext} resolving provider roots and installation state.
 */
#[Test]
#[Covers(ProjectContext::class)]
final class ProjectContextTest
{
    use TempDirectoryTrait;

    /**
     * Ensures the Composer install path outside the vendor directory wins over the lock path without a warning.
     */
    public function installPathOutsideVendorWinsWithoutWarning(): void
    {
        mkdir("{$this->tempDir}/packages/p-a", 0o777, true);

        $context = new ProjectContext(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            new ProjectConfig(['p/a']),
            new InstalledPackages(['p/a' => ['version' => '1.0.0', 'path' => "{$this->tempDir}/packages/p-a"]]),
        );

        Assert::same(
            $context->providerRoot('p/a', ['path' => 'vendor/p/a']),
            ['root' => "{$this->tempDir}/packages/p-a", 'warning' => null],
            'Composer install path must win over the lock path.',
        );
        Assert::true(
            $context->isInstalled('p/a'),
            'Listed package is installed.',
        );
        Assert::false(
            $context->isInstalled('p/b'),
            'Unlisted package is not installed.',
        );
    }

    /**
     * Ensures a known installed list decides alone, whatever the provider directories on disk.
     */
    public function knownInstalledListDecidesWithoutTheDirectory(): void
    {
        mkdir("{$this->tempDir}/vendor/p/b", 0o777, true);

        $context = new ProjectContext(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            new ProjectConfig(['p/a', 'p/b']),
            new InstalledPackages(['p/a' => ['version' => '1.0.0', 'path' => "{$this->tempDir}/vendor/p/a"]]),
        );

        Assert::same(
            [$context->isInstalled('p/a'), $context->isInstalled('p/b')],
            [true, false],
            'Listed package without a directory is installed; unlisted package with one is not.',
        );
    }

    /**
     * Ensures allowed packages that are not installed are left out of the providers.
     */
    public function providersSkipAllowedPackagesThatAreNotInstalled(): void
    {
        mkdir("{$this->tempDir}/vendor/p/a", 0o777, true);

        $context = new ProjectContext(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            new ProjectConfig(['p/missing', 'p/a']),
            InstalledPackages::fromRawData(
                [
                    'versions' => [
                        'p/a' => ['pretty_version' => '1.2.0', 'install_path' => "{$this->tempDir}/vendor/p/a"],
                    ],
                ],
            ),
        );

        $providers = $context->providers([])['providers'];

        Assert::same(
            array_keys($providers),
            ['p/a'],
            'Only the installed provider must be listed.',
        );
        Assert::same(
            ($providers['p/a'] ?? null)?->prettyVersion,
            '1.2.0',
            'Installed version must be used.',
        );
    }

    /**
     * Ensures a symlinked path repository resolves to its target through `installed.php`.
     */
    public function symlinkedPathRepositoryResolvesThroughInstalledPhp(): void
    {
        mkdir("{$this->tempDir}/packages/p-a", 0o777, true);
        mkdir("{$this->tempDir}/vendor/p", 0o777, true);
        symlink("{$this->tempDir}/packages/p-a", "{$this->tempDir}/vendor/p/a");

        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])->installed(['p/a' => 'dev-main']);

        Assert::same(
            $project->context()->providerRoot('p/a', ['path' => 'packages/p-a']),
            ['root' => realpath("{$this->tempDir}/packages/p-a"), 'warning' => null],
            'Symlink target outside vendor must resolve without warning.',
        );
    }

    /**
     * Ensures an unknown installed list falls back to the lock path and the existing directory.
     */
    public function unknownInstalledListFallsBackToLockPathAndDirectory(): void
    {
        mkdir("{$this->tempDir}/vendor/p/a", 0o777, true);

        $context = new ProjectContext(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            new ProjectConfig(['p/a']),
            new InstalledPackages(null),
        );

        Assert::same(
            $context->providerRoot('p/a', ['path' => 'vendor/p/a'])['root'],
            realpath("{$this->tempDir}/vendor/p/a"),
            'Lock path must be used.',
        );
        Assert::true(
            $context->isInstalled('p/a'),
            'Existing root directory counts as installed.',
        );
        Assert::false(
            $context->isInstalled('p/b'),
            'Missing root directory is not installed.',
        );
    }
}
