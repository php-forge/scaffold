<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Config;

use PHPForge\Scaffold\Security\PathValidator;

use function dirname;
use function is_file;
use function rtrim;

/**
 * Finds the project root by walking up from a directory to the nearest one that contains the Composer manifest.
 */
final class ProjectRootFinder
{
    /**
     * Returns the nearest directory, starting at `$directory` and walking up, that contains the Composer manifest
     * named by {@see VendorDirResolver::manifestName()}.
     *
     * An absolute manifest path is not searched for: its directory is the project root, as for the Composer plugin.
     *
     * @param string $directory Absolute path of the directory where the search starts.
     *
     * @return string|null Absolute path of the project root, or `null` when the filesystem root is reached first or the
     * absolute manifest does not exist.
     */
    public static function find(string $directory): string|null
    {
        $manifest = VendorDirResolver::manifestName();

        if (PathValidator::isAbsolute($manifest)) {
            return is_file($manifest) ? dirname($manifest) : null;
        }

        $current = rtrim($directory, '/\\');

        if ($current === '') {
            $current = DIRECTORY_SEPARATOR;
        }

        while (true) {
            if (is_file($current . DIRECTORY_SEPARATOR . $manifest)) {
                return $current;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }
    }
}
