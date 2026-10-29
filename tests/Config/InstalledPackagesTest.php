<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Config;

use PHPForge\Scaffold\Config\InstalledPackages;
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function dirname;
use function realpath;

/**
 * Unit tests for {@see InstalledPackages} reading `vendor/composer/installed.php` and the Composer runtime data.
 */
#[Test]
#[Covers(InstalledPackages::class)]
final class InstalledPackagesTest
{
    use TempDirectoryTrait;

    /**
     * Ensures the versions and canonical install paths are read from `installed.php` of the vendor directory.
     */
    public function detectReadsInstalledPhpOfTheVendorDirectory(): void
    {
        (new InspectionProject($this->tempDir))
            ->provider('p/a', ['a.txt' => "a\n"])
            ->installed(['p/a' => '1.2.3']);

        $installed = InstalledPackages::detect("{$this->tempDir}/vendor", $this->tempDir);

        Assert::true(
            $installed->isKnown(),
            'Installed data must be known.',
        );
        Assert::true(
            $installed->has('p/a'),
            'Listed package must be installed.',
        );
        Assert::false(
            $installed->has('p/b'),
            'Unlisted package must not be installed.',
        );
        Assert::same(
            $installed->version('p/a'),
            '1.2.3',
            'Pretty version must be read.',
        );
        Assert::same(
            $installed->installPath('p/a'),
            realpath("{$this->tempDir}/vendor/p/a"),
            'Install path must be canonical.',
        );
    }

    /**
     * Ensures the runtime installed data is used when its root is the inspected project.
     */
    public function detectUsesRuntimeDataWhenItsRootIsTheProject(): void
    {
        $root = dirname(__DIR__, 2);

        $installed = InstalledPackages::detect("{$this->tempDir}/missing-vendor", $root);

        Assert::true(
            $installed->isKnown(),
            'Runtime data of this project must be used.',
        );
        Assert::true(
            $installed->has('symfony/console'),
            "Runtime packages must list 'symfony/console'.",
        );
        Assert::notNull(
            $installed->installPath('symfony/console'),
            "Runtime install path of 'symfony/console' must be known.",
        );
    }

    /**
     * Ensures another project without `installed.php` yields an unknown list.
     */
    public function detectYieldsUnknownListForAnotherProjectWithoutInstalledPhp(): void
    {
        $installed = InstalledPackages::detect("{$this->tempDir}/vendor", $this->tempDir);

        Assert::false(
            $installed->isKnown(),
            'Runtime data of another root must be ignored.',
        );
        Assert::false(
            $installed->has('symfony/console'),
            "Unknown list must not hold 'symfony/console'.",
        );
        Assert::null(
            $installed->version('symfony/console'),
            "Unknown list must not hold a version of 'symfony/console'.",
        );
    }

    /**
     * Ensures replaced packages and metapackages are skipped and later entries are still read.
     */
    public function fromRawDataSkipsEntriesWithoutInstallPath(): void
    {
        $installed = InstalledPackages::fromRawData(
            [
                'versions' => [
                    'p/a' => ['pretty_version' => '1.0.0', 'install_path' => $this->tempDir],
                    'p/replaced' => ['replaced' => ['1.0.0']],
                    'p/meta' => ['pretty_version' => '2.0.0', 'install_path' => null],
                    'p/no-version' => ['install_path' => $this->tempDir],
                ],
            ],
        );

        Assert::true(
            $installed->has('p/a'),
            'Package with a path is installed.',
        );
        Assert::false(
            $installed->has('p/replaced'),
            'Replaced package has no install path.',
        );
        Assert::false(
            $installed->has('p/meta'),
            'Metapackage has no install path.',
        );
        Assert::true(
            $installed->has('p/no-version'),
            'Entries after a skipped one must be read.',
        );
        Assert::null(
            $installed->version('p/no-version'),
            "Missing version yields 'null'.",
        );
    }

    /**
     * Ensures raw data without a `versions` map yields an unknown list.
     */
    public function fromRawDataWithoutVersionsMapIsUnknown(): void
    {
        Assert::false(
            InstalledPackages::fromRawData(1)->isKnown(),
            'A non-array yields an unknown list.',
        );
        Assert::false(
            InstalledPackages::fromRawData(['root' => []])->isKnown(),
            'Missing versions is unknown.',
        );
    }
}
