<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Modes;

use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use PHPForge\Scaffold\Manifest\FileMapping;
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use PHPForge\Scaffold\Scaffold\PathResolver;
use RuntimeException;

/**
 * Applies a file by overwriting the destination unless it changed since its recorded hash.
 */
final class ReplaceMode implements ModeInterface
{
    /**
     * Copies the source over the destination, skipping a destination whose hash differs from the recorded one and
     * warning when it overwrites an untracked destination with different content.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param string|null $hashAtScaffold Hash recorded in the lock file, or `null` when the destination is untracked.
     *
     * @throws RuntimeException when a file cannot be read, written or hashed, or a directory cannot be created.
     *
     * @return ApplyResult `Written` with the new hash and any warning, or `Skipped` with an empty hash and a warning
     * when the destination is user-modified.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = PathResolver::destination($projectRoot, $mapping->destination);
        $source = PathResolver::source($mapping->providerPath, $mapping->source);

        $warning = null;

        if (file_exists($destination)) {
            $currentHash = Hasher::hash($destination);

            if ($hashAtScaffold !== null && $currentHash !== $hashAtScaffold) {
                return new ApplyResult(
                    ApplyOutcome::Skipped,
                    '',
                    ConsoleMessage::SCAFFOLD_USER_MODIFIED_SKIPPED->getMessage($mapping->destination),
                );
            }

            if ($hashAtScaffold === null && $currentHash !== Hasher::hash($source)) {
                $warning = ConsoleMessage::SCAFFOLD_UNTRACKED_OVERWRITTEN->getMessage($mapping->destination);
            }
        }

        PathResolver::ensureDirectory($destination);
        Filesystem::copy($source, $destination, Message::MODE_COPY_FAILED->getMessage($source, $destination));

        $permissionWarning = PathResolver::syncPermissions($source, $destination);

        if ($permissionWarning !== null) {
            $warning = $warning === null ? $permissionWarning : "{$warning} {$permissionWarning}";
        }

        return new ApplyResult(ApplyOutcome::Written, Hasher::hash($destination), $warning);
    }
}
