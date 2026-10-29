<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Config;

use PHPForge\Scaffold\Scaffold\PathResolver;
use RuntimeException;

use function is_array;
use function is_dir;
use function is_string;

/**
 * Represents the project that the scaffold commands inspect: its root, vendor directory, configuration and installed
 * packages.
 */
final readonly class ProjectContext
{
    /**
     * @param string $projectRoot Absolute path to the project root.
     * @param string $vendorDir Absolute path to the Composer vendor directory.
     * @param ProjectConfig $config Consumer settings from the project `composer.json`.
     * @param InstalledPackages $installed Packages installed in the project.
     */
    public function __construct(
        public string $projectRoot,
        public string $vendorDir,
        public ProjectConfig $config,
        public InstalledPackages $installed,
    ) {}

    /**
     * Creates the context of the project rooted at `$projectRoot` from its `composer.json` and installed packages.
     *
     * @param string $projectRoot Absolute path to the project root.
     *
     * @throws RuntimeException when the project `composer.json` is missing or invalid.
     *
     * @return self Project context with the configuration and installed packages of the project.
     */
    public static function create(string $projectRoot): self
    {
        $config = ProjectConfig::fromComposerJson(VendorDirResolver::manifestPath($projectRoot));
        $vendorDir = VendorDirResolver::resolve($projectRoot, $config->vendorDir);

        return new self($projectRoot, $vendorDir, $config, InstalledPackages::detect($vendorDir, $projectRoot));
    }

    /**
     * Returns whether the provider is installed: from the installed packages when known, otherwise from the
     * existence of its resolved root directory.
     *
     * @param string $providerName Provider package name.
     * @param mixed $providerLock Decoded `providers.<name>` entry of `scaffold-lock.json`, or `null` when absent.
     *
     * @return bool `true` when the provider is installed in the project, `false` otherwise.
     */
    public function isInstalled(string $providerName, mixed $providerLock = null): bool
    {
        if ($this->installed->isKnown()) {
            return $this->installed->has($providerName);
        }

        return is_dir($this->providerRoot($providerName, $providerLock)['root']);
    }

    /**
     * Resolves the root of a provider from its Composer install path, then its lock-recorded path inside the vendor
     * directory, then `<vendorDir>/<providerName>`.
     *
     * @param string $providerName Provider package name.
     * @param mixed $providerLock Decoded `providers.<name>` entry of `scaffold-lock.json`, or `null` when absent.
     *
     * @return array{root: string, warning: string|null} Provider root, and a warning when the lock-recorded path
     * resolves outside the vendor directory.
     */
    public function providerRoot(string $providerName, mixed $providerLock = null): array
    {
        $installPath = $this->installed->installPath($providerName);

        if ($installPath !== null) {
            return [
                'root' => $installPath,
                'warning' => null,
            ];
        }

        return PathResolver::resolveProviderRoot($this->vendorDir, $providerName, $providerLock, $this->projectRoot);
    }

    /**
     * Returns the allowed providers that are installed, as descriptors whose `extra` the scaffold core reads from
     * their `composer.json`.
     *
     * @param array<string, mixed> $lockProviders `providers` map of `scaffold-lock.json`, used for recorded paths and,
     * when the installed packages are unknown, recorded versions.
     *
     * @return array{providers: array<string, ProviderPackage>, warnings: array<string, string>} Providers keyed by name
     * in `allowed-packages` order, and warnings about provider roots keyed by provider name.
     */
    public function providers(array $lockProviders): array
    {
        $providers = [];
        $warnings = [];

        foreach ($this->config->allowedPackages as $name) {
            $providerLock = $lockProviders[$name] ?? null;

            if (!$this->isInstalled($name, $providerLock)) {
                continue;
            }

            $resolved = $this->providerRoot($name, $providerLock);

            if ($resolved['warning'] !== null) {
                $warnings[$name] = $resolved['warning'];
            }

            $lockVersion = is_array($providerLock) ? $providerLock['version'] ?? null : null;

            $providers[$name] = new ProviderPackage(
                $name,
                $this->installed->version($name) ?? (is_string($lockVersion) ? $lockVersion : 'unknown'),
                $resolved['root'],
            );
        }

        return [
            'providers' => $providers,
            'warnings' => $warnings,
        ];
    }
}
