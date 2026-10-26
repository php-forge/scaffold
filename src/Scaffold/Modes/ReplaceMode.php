<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Modes;

use PHPForge\Scaffold\Manifest\FileMapping;
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use PHPForge\Scaffold\Scaffold\PathResolver;
use RuntimeException;

use function sprintf;

/**
 * Applies a scaffold file by overwriting the destination, unless the user has modified it.
 *
 * When a lock hash exists and the current file hash differs, the file is considered user-modified and the write is
 * skipped with a warning. When no lock hash exists, or hashes match, the source is copied unconditionally.
 */
final class ReplaceMode implements ModeInterface
{
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = PathResolver::destination($projectRoot, $mapping->destination);
        $source = PathResolver::source($mapping->providerPath, $mapping->source);

        if (file_exists($destination) && $hashAtScaffold !== null) {
            $currentHash = $hasher->hash($destination);

            if (!$hasher->equals($currentHash, $hashAtScaffold)) {
                return new ApplyResult(
                    ApplyOutcome::Skipped,
                    '',
                    sprintf('User-modified file skipped: "%s".', $mapping->destination),
                );
            }
        }

        PathResolver::ensureDirectory($destination);

        if (copy($source, $destination) === false) {
            throw new RuntimeException(
                sprintf('Could not copy "%s" to "%s".', $source, $destination),
            );
        }

        PathResolver::syncPermissions($source, $destination);

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }
}
