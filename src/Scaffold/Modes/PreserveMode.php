<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Modes;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use PHPForge\Scaffold\Manifest\FileMapping;
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use PHPForge\Scaffold\Scaffold\PathResolver;
use RuntimeException;

/**
 * Applies a file only when the destination does not exist.
 */
final class PreserveMode implements ModeInterface
{
    /**
     * Copies the source when the destination is missing; otherwise returns `Skipped` with the current hash.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param string|null $hashAtScaffold Hash recorded in the lock file, or `null` when the destination is untracked;
     * this mode ignores it.
     *
     * @throws RuntimeException when a file cannot be read, written or hashed, or a directory cannot be created.
     *
     * @return ApplyResult `Written` with the new hash and any permission warning when the destination is missing,
     * `Skipped` with the current hash otherwise.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = PathResolver::destination($projectRoot, $mapping->destination);

        if (file_exists($destination)) {
            return new ApplyResult(ApplyOutcome::Skipped, Hasher::hash($destination), null);
        }

        $source = PathResolver::source($mapping->providerPath, $mapping->source);

        PathResolver::ensureDirectory($destination);
        Filesystem::copy($source, $destination, Message::MODE_COPY_FAILED->getMessage($source, $destination));

        return new ApplyResult(
            ApplyOutcome::Written,
            Hasher::hash($destination),
            PathResolver::syncPermissions($source, $destination),
        );
    }
}
