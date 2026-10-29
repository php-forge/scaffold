<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold;

use Composer\Package\PackageInterface;
use PHPForge\Scaffold\Config\{ProjectConfig, ProviderPackage};
use PHPForge\Scaffold\Console\{Message, OutputWriter};
use PHPForge\Scaffold\Manifest\ManifestLoader;
use PHPForge\Scaffold\Scaffold\Lock\LockFile;

/**
 * Applies every authorized provider's files to the project inside a Composer run by converting Composer's packages
 * for the Composer-free {@see ScaffoldRunner}.
 */
final readonly class Scaffolder
{
    /**
     * @param ManifestLoader $loader Loads provider manifests into file mappings.
     * @param FileApplier $applier Validates and applies each mapping.
     * @param LockFile $lockFile Lock file of the project.
     * @param OutputWriter $out Output sink for notices and warnings.
     * @param ScaffoldSummary $summary Counters that the runs of this scaffolder update.
     */
    public function __construct(
        private ManifestLoader $loader,
        private FileApplier $applier,
        private LockFile $lockFile,
        private OutputWriter $out,
        private ScaffoldSummary $summary,
    ) {}

    /**
     * Applies the files of every installed provider listed in `allowed-packages` and records them in
     * `scaffold-lock.json`; a later provider overrides an earlier one per destination, and ejected files stay
     * untouched.
     *
     * @param ProjectConfig $config Consumer settings of the root package.
     * @param PackageInterface[] $installedPackages Packages installed in the project.
     * @param string $projectRoot Absolute path to the project root.
     * @param string $vendorDir Absolute path to the vendor directory, used for providers missing from `$installPaths`.
     * @param array<string, string> $installPaths Absolute install paths keyed by package name, as resolved by
     * Composer's `InstallationManager`.
     *
     * @throws \PHPForge\Scaffold\Exception\ScaffoldException when the manifest of an allowed provider cannot be loaded.
     * @throws \RuntimeException when the lock file cannot be read or written.
     */
    public function scaffold(
        ProjectConfig $config,
        array $installedPackages,
        string $projectRoot,
        string $vendorDir,
        array $installPaths = [],
    ): void {
        if (!$config->declared) {
            $this->out->writeStdout(
                Message::SCAFFOLD_CONFIG_MISSING->getMessage(),
            );

            return;
        }

        if ($config->allowedPackages === [] && $config->ignoredEntries === 0) {
            $this->out->writeStdout(
                Message::SCAFFOLD_NO_ALLOWED_PACKAGES->getMessage(),
            );

            return;
        }

        $providers = [];

        foreach ($installedPackages as $package) {
            $name = $package->getName();

            $providers[$name] = new ProviderPackage(
                $name,
                $package->getPrettyVersion(),
                $package->getType() === 'metapackage' ? null : $installPaths[$name] ?? "{$vendorDir}/{$name}",
                $package->getExtra(),
            );
        }

        (new ScaffoldRunner($this->loader, $this->applier, $this->lockFile, $this->out, $this->summary))
            ->run($config, $providers, $projectRoot);
    }
}
