<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Config;

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
     * @param string $directory Absolute path of the directory where the search starts.
     *
     * @return string|null Absolute path of the project root, or `null` when the filesystem root is reached first.
     */
    public static function find(string $directory): string|null
    {
        $manifest = VendorDirResolver::manifestName();

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
