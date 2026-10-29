<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Merge\StrategyResolver;
use PHPForge\Scaffold\Scaffold\Lock\{Hasher, LockFile};
use PHPForge\Scaffold\Scaffold\Modes\PrependMode;
use PHPForge\Scaffold\Scaffold\PathResolver;
use RuntimeException;

use function file_exists;
use function is_file;

/**
 * Computes in memory what applying a provider mapping would write to its destination, without touching the disk.
 *
 * @phpstan-import-type LockEntry from LockFile
 */
final readonly class ApplyPreviewer
{
    /**
     * Returns the current and proposed content of the mapping destination: the stub for `replace` and `preserve`, the
     * merge result for `append`, and the prepended block for `prepend`.
     *
     * @param FileMapping $mapping Validated mapping to preview.
     * @param string $projectRoot Absolute path to the project root.
     * @param LockEntry|null $entry Lock entry of the destination, or `null` when it is untracked.
     *
     * @throws RuntimeException when the stub is missing or a file cannot be read or hashed.
     *
     * @return ApplyPreview Current and proposed content of the destination, with its `replace` and `preserve` flags.
     */
    public function preview(FileMapping $mapping, string $projectRoot, array|null $entry = null): ApplyPreview
    {
        $stubPath = PathResolver::source($mapping->providerPath, $mapping->source);

        if (!is_file($stubPath)) {
            throw new RuntimeException(
                Message::SERVICE_STUB_NOT_FOUND->getMessage($stubPath),
            );
        }

        $stub = Filesystem::read($stubPath, Message::SERVICE_STUB_UNREADABLE->getMessage($stubPath));

        $destPath = PathResolver::destination($projectRoot, $mapping->destination);
        $exists = file_exists($destPath);

        $current = $exists
            ? Filesystem::read($destPath, Message::SERVICE_CURRENT_FILE_UNREADABLE->getMessage($destPath))
            : '';

        return match ($mapping->mode) {
            FileMode::Replace => new ApplyPreview(
                $mapping,
                $exists,
                $current,
                $stub,
                userModified: $exists && $this->isUserModified($destPath, $entry),
            ),
            FileMode::Preserve => new ApplyPreview(
                $mapping,
                $exists,
                $current,
                $stub,
                informational: $exists,
            ),
            FileMode::Append => new ApplyPreview(
                $mapping,
                $exists,
                $current,
                (
                    $mapping->mergeStrategy !== null
                        ? StrategyResolver::forKind($mapping->mergeStrategy)
                        : StrategyResolver::for($mapping->destination)
                )->merge($stub, $current)->content,
            ),
            FileMode::Prepend => new ApplyPreview(
                $mapping,
                $exists,
                $current,
                PrependMode::prepend($stub, $exists ? $current : null) ?? $current,
            ),
        };
    }

    /**
     * Returns whether an existing `replace` destination is adopted or differs from its recorded hash.
     *
     * @param string $destPath Absolute path to the existing destination.
     * @param LockEntry|null $entry Lock entry of the destination, or `null` when it is untracked.
     *
     * @throws RuntimeException when the destination cannot be hashed.
     *
     * @return bool `true` when the tracked destination is adopted or changed since the last apply, `false` otherwise.
     */
    private function isUserModified(string $destPath, array|null $entry): bool
    {
        if ($entry === null) {
            return false;
        }

        if (($entry['adopted'] ?? false) === true) {
            return true;
        }

        return Hasher::hash($destPath) !== $entry['hash'];
    }
}
