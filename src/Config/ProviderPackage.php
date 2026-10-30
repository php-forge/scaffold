<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Config;

/**
 * Represents an installed provider package as the scaffold core sees it, independent of Composer's package classes.
 */
final readonly class ProviderPackage
{
    /**
     * Creates an installed provider descriptor.
     *
     * @param string $name Composer package name.
     * @param string $prettyVersion Installed version recorded in `scaffold-lock.json`.
     * @param string|null $installPath Absolute install path, or `null` for a package without files, such as a
     * metapackage.
     * @param array<mixed>|null $extra `extra` section of the package, or `null` to read it from the `composer.json` at
     * `$installPath`.
     */
    public function __construct(
        public string $name,
        public string $prettyVersion,
        public string|null $installPath,
        public array|null $extra = null,
    ) {}
}
