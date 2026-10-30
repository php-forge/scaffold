<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Config;

use Composer\InstalledVersions;
use PHPForge\Scaffold\Scaffold\PathResolver;

use function array_key_exists;
use function is_array;
use function is_file;
use function is_string;

/**
 * Represents the packages Composer installed in a project, read from its `vendor/composer/installed.php`.
 */
final readonly class InstalledPackages
{
    /**
     * Creates an installed-package view from normalized Composer metadata.
     *
     * @param array<string, array{version: string|null, path: string|null}>|null $packages Installed packages keyed by
     * name, with their pretty version and absolute install path, or `null` when the installed packages are unknown.
     */
    public function __construct(private array|null $packages) {}

    /**
     * Reads the installed packages of the project from `<vendorDir>/composer/installed.php`, then from the
     * `Composer\InstalledVersions` runtime data when its root package is `$projectRoot`.
     *
     * @param string $vendorDir Absolute path to the Composer vendor directory of the project.
     * @param string $projectRoot Absolute path to the project root.
     *
     * @return self Installed packages, or an unknown list when neither source describes the project.
     */
    public static function detect(string $vendorDir, string $projectRoot): self
    {
        $file = PathResolver::destination($vendorDir, 'composer/installed.php');

        if (is_file($file)) {
            return self::fromRawData(require $file);
        }

        $rootPath = InstalledVersions::getRootPackage()['install_path'];

        if (PathResolver::realpathOrFallback($rootPath) !== PathResolver::realpathOrFallback($projectRoot)) {
            return new self(null);
        }

        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $packages[$name] = [
                'version' => InstalledVersions::getPrettyVersion($name),
                'path' => InstalledVersions::getInstallPath($name),
            ];
        }

        return new self($packages);
    }

    /**
     * Builds the list from the array returned by a `vendor/composer/installed.php` file.
     *
     * @param mixed $data Decoded installed data with a `versions` map; any other value yields an unknown list.
     *
     * @return self Installed packages that declare an install path, or an unknown list when `$data` is malformed.
     */
    public static function fromRawData(mixed $data): self
    {
        if (!is_array($data) || !is_array($data['versions'] ?? null)) {
            return new self(null);
        }

        $packages = [];

        foreach ($data['versions'] as $name => $info) {
            if (!is_string($name) || !is_array($info) || !is_string($info['install_path'] ?? null)) {
                continue;
            }

            $version = $info['pretty_version'] ?? null;

            $packages[$name] = [
                'version' => is_string($version) ? $version : null,
                'path' => PathResolver::realpathOrFallback($info['install_path']),
            ];
        }

        return new self($packages);
    }

    /**
     * Returns whether `$name` is installed; `false` when the installed packages are unknown.
     *
     * @param string $name Package name to look up.
     *
     * @return bool `true` when the package is installed, `false` when it is not installed or the list is unknown.
     */
    public function has(string $name): bool
    {
        return $this->packages !== null && array_key_exists($name, $this->packages);
    }

    /**
     * Returns the absolute install path of `$name`, or `null` when it is not installed or unknown.
     *
     * @param string $name Package name to look up.
     *
     * @return string|null Absolute install path, or `null` when the package is not installed or the list is unknown.
     */
    public function installPath(string $name): string|null
    {
        return $this->packages[$name]['path'] ?? null;
    }

    /**
     * Returns whether the installed packages are known.
     *
     * @return bool `true` when an installed package source describes the project, `false` otherwise.
     */
    public function isKnown(): bool
    {
        return $this->packages !== null;
    }

    /**
     * Returns the installed pretty version of `$name`, or `null` when it is not installed or unknown.
     *
     * @param string $name Package name to look up.
     *
     * @return string|null Pretty version, or `null` when it is missing, not installed, or the list is unknown.
     */
    public function version(string $name): string|null
    {
        return $this->packages[$name]['version'] ?? null;
    }
}
