<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Modes;

use PHPForge\Scaffold\Manifest\FileMapping;
use RuntimeException;

/**
 * Defines how a write mode applies a file mapping to the project.
 */
interface ModeInterface
{
    /**
     * Applies a file mapping to the project and returns the outcome, the destination hash and an optional warning.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param string|null $hashAtScaffold Hash recorded in the lock file, or `null` when the destination is untracked.
     *
     * @throws RuntimeException when a file cannot be read, written or hashed, or a directory cannot be created.
     *
     * @return ApplyResult Outcome, destination hash and optional warning of the operation.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        string|null $hashAtScaffold,
    ): ApplyResult;
}
