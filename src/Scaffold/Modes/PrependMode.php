<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Modes;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use PHPForge\Scaffold\Manifest\FileMapping;
use PHPForge\Scaffold\Merge\Lines;
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use PHPForge\Scaffold\Scaffold\PathResolver;
use RuntimeException;

use function array_keys;
use function array_slice;
use function count;
use function implode;

/**
 * Applies a file by prepending the source block unless the destination already contains it.
 */
final class PrependMode implements ModeInterface
{
    /**
     * Prepends the source block, terminated with the destination end-of-line sequence, when the destination lacks it
     * as a contiguous run of lines.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param string|null $hashAtScaffold Hash recorded in the lock file, or `null` when the destination is untracked;
     * this mode ignores it.
     *
     * @throws RuntimeException when a file cannot be read, written or hashed, or a directory cannot be created.
     *
     * @return ApplyResult `Written` with the new hash when the block is prepended, `Skipped` with the current hash
     * when the destination already contains it.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = PathResolver::destination($projectRoot, $mapping->destination);
        $source = PathResolver::source($mapping->providerPath, $mapping->source);
        $sourceContent = Filesystem::read($source, Message::MODE_SOURCE_UNREADABLE->getMessage($source));

        $existing = file_exists($destination)
            ? Filesystem::read($destination, Message::MODE_DESTINATION_UNREADABLE->getMessage($destination))
            : null;

        $content = self::prepend($sourceContent, $existing);

        if ($content === null) {
            return new ApplyResult(ApplyOutcome::Skipped, Hasher::hash($destination), null);
        }

        PathResolver::ensureDirectory($destination);

        Filesystem::write($destination, $content, Message::MODE_WRITE_FAILED->getMessage($destination));

        return new ApplyResult(ApplyOutcome::Written, Hasher::hash($destination), null);
    }

    /**
     * Returns the destination content after prepending the source block, terminated with the destination end-of-line
     * sequence, or `null` when the destination already contains the block as a contiguous run of lines.
     *
     * @param string $source Provider content.
     * @param string|null $existing Destination content, or `null` when the destination does not exist.
     *
     * @return string|null Content to write, or `null` when the destination already contains the block.
     */
    public static function prepend(string $source, string|null $existing): string|null
    {
        if ($existing === null) {
            return $source;
        }

        $block = Lines::split(Lines::normalise($source));

        if ($block === [] || self::containsBlock(Lines::split(Lines::normalise($existing)), $block)) {
            return null;
        }

        if ($existing === '') {
            return $source;
        }

        $eol = Lines::detectEol($existing);

        return implode($eol, $block) . $eol . $existing;
    }

    /**
     * Returns whether the block occurs in the lines as a contiguous run.
     *
     * @param list<string> $lines Destination lines.
     * @param non-empty-list<string> $block Provider lines.
     *
     * @return bool `true` when the block occurs as a contiguous run of lines, `false` otherwise.
     */
    private static function containsBlock(array $lines, array $block): bool
    {
        $size = count($block);

        // A run that starts too close to the end is shorter than the block, so it never matches.
        foreach (array_keys($lines) as $offset) {
            if (array_slice($lines, $offset, $size) === $block) {
                return true;
            }
        }

        return false;
    }
}
