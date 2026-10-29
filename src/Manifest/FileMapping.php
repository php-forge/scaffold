<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Manifest;

use PHPForge\Scaffold\Merge\MergeStrategyKind;

/**
 * Represents one provider file to apply: its source, destination, write mode and merge strategy.
 */
final readonly class FileMapping
{
    /**
     * @param string $destination Destination path relative to the project root, using forward slashes.
     * @param string $source Source path relative to the provider root, using forward slashes.
     * @param FileMode $mode Write mode applied to the destination.
     * @param string $providerName Composer package name of the provider.
     * @param string $providerPath Absolute path to the provider root on disk.
     * @param MergeStrategyKind|null $mergeStrategy Merge strategy declared by the manifest `merge` map for the
     * `append` mode, or `null` to resolve it from the destination basename.
     */
    public function __construct(
        public string $destination,
        public string $source,
        public FileMode $mode,
        public string $providerName,
        public string $providerPath,
        public MergeStrategyKind|null $mergeStrategy = null,
    ) {}
}
