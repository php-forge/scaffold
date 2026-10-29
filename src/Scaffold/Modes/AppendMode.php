<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Modes;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use PHPForge\Scaffold\Manifest\FileMapping;
use PHPForge\Scaffold\Merge\StrategyResolver;
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use PHPForge\Scaffold\Scaffold\PathResolver;
use RuntimeException;

/**
 * Applies a file by merging the provider entries that the destination lacks, or by writing the source when the
 * destination is missing.
 */
final class AppendMode implements ModeInterface
{
    /**
     * Merges the source into the destination with the mapping's merge strategy, or the default strategy for the
     * destination name, and writes only when the content changes.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param string|null $hashAtScaffold Hash recorded in the lock file, or `null` when the destination is untracked;
     * this mode ignores it.
     *
     * @throws RuntimeException when a file cannot be read, written or hashed, or a directory cannot be created.
     *
     * @return ApplyResult `Written` with the new hash when the destination is missing or the merge changes it,
     * `Skipped` with the current hash otherwise.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = PathResolver::destination($projectRoot, $mapping->destination);
        $source = PathResolver::source($mapping->providerPath, $mapping->source);
        $providerContent = Filesystem::read($source, Message::MODE_SOURCE_UNREADABLE->getMessage($source));

        $exists = file_exists($destination);

        $consumerContent = $exists
            ? Filesystem::read($destination, Message::MODE_DESTINATION_UNREADABLE->getMessage($destination))
            : '';

        $strategy = $mapping->mergeStrategy !== null
            ? StrategyResolver::forKind($mapping->mergeStrategy)
            : StrategyResolver::for($mapping->destination);

        $result = $strategy->merge($providerContent, $consumerContent);

        if ($exists && !$result->changed) {
            return new ApplyResult(ApplyOutcome::Skipped, Hasher::hash($destination), null);
        }

        PathResolver::ensureDirectory($destination);
        Filesystem::write($destination, $result->content, Message::MODE_WRITE_FAILED->getMessage($destination));

        return new ApplyResult(ApplyOutcome::Written, Hasher::hash($destination), null);
    }
}
