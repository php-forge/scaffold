<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Modes;

use PHPForge\Scaffold\Manifest\FileMapping;
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use RuntimeException;

/**
 * Contract for all scaffold file-application strategies.
 */
interface ModeInterface
{
    /**
     * Applies a file mapping to the project root and returns the result.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param Hasher $hasher Hash utility for computing and comparing file hashes.
     * @param string|null $hashAtScaffold Hash recorded in the lock file at last scaffold time, or `null` if untracked.
     *
     * @throws RuntimeException when the source file cannot be read or the destination cannot be written.
     *
     * @return ApplyResult Result of the apply operation, including the outcome, new hash, and any warning message.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult;
}
