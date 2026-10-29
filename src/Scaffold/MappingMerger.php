<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold;

use PHPForge\Scaffold\Manifest\FileMapping;

/**
 * Merges provider file mappings by destination; a later provider overrides an earlier one for the same destination.
 */
final class MappingMerger
{
    /**
     * Adds `$mappings` to `$merged`, replacing the mapping already recorded for the same destination.
     *
     * @param array<array-key, FileMapping> $merged Mappings keyed by destination, updated in place; integer-like
     * destinations (`"123"`) become integer keys.
     * @param list<FileMapping> $mappings Mappings of the next provider, in manifest order.
     *
     * @return list<array{FileMapping, FileMapping}> Overrides across providers, as pairs of the new mapping and the
     * mapping it replaced.
     */
    public static function merge(array &$merged, array $mappings): array
    {
        $overrides = [];

        foreach ($mappings as $mapping) {
            $previous = $merged[$mapping->destination] ?? null;

            if ($previous !== null && $previous->providerName !== $mapping->providerName) {
                $overrides[] = [$mapping, $previous];
            }

            $merged[$mapping->destination] = $mapping;
        }

        return $overrides;
    }
}
