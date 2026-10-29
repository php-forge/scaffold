<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Manifest;

/**
 * Represents the write modes that a provider manifest assigns to destinations.
 */
enum FileMode: string
{
    /**
     * Merges the provider entries missing from the destination on every run, creating the destination when missing.
     */
    case Append = 'append';

    /**
     * Prepends the provider block on every run unless the destination already contains it.
     */
    case Prepend = 'prepend';

    /**
     * Writes the destination only when it is missing; only `reapply --force` overwrites an existing file.
     */
    case Preserve = 'preserve';

    /**
     * Overwrites the destination unless it changed since its recorded hash or was adopted from an existing file.
     */
    case Replace = 'replace';
}
