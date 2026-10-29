<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Merge;

use function array_flip;
use function array_map;
use function array_slice;
use function str_starts_with;
use function trim;

/**
 * Merges ignore-style list files (`.gitignore`, `.gitattributes` and similar) by entry, never by comment.
 *
 * @phpstan-import-type MergePlan from AbstractLineMergeStrategy
 */
final class ListMergeStrategy extends AbstractLineMergeStrategy
{
    /**
     * Plans missing entries under the consumer's matching comment header, or as new commented blocks at the end.
     *
     * @param list<string> $provider Provider lines.
     * @param list<string> $consumer Consumer lines without terminators.
     *
     * @return MergePlan Lines to insert after a consumer line index and blocks to append; both empty when satisfied.
     */
    protected function plan(array $provider, array $consumer): array
    {
        // Blank and comment lines never match a provider entry, so every trimmed consumer line can be indexed.
        $present = array_flip(array_map(trim(...), $consumer));

        $insertAfter = [];
        $blocks = [];

        foreach (self::groups($provider) as [$comments, $entries]) {
            $missing = [];

            foreach ($entries as $entry) {
                if (!isset($present[$entry])) {
                    $present[$entry] = $entry;
                    $missing[] = $entry;
                }
            }

            if ($missing === []) {
                continue;
            }

            $anchor = $comments === [] ? null : self::locate($consumer, trim($comments[0]));

            if ($anchor === null) {
                $blocks[] = [...$comments, ...$missing];

                continue;
            }

            foreach ($missing as $entry) {
                $insertAfter[$anchor][] = $entry;
            }
        }

        return [
            'insertAfter' => $insertAfter,
            'blocks' => $blocks,
        ];
    }

    /**
     * Parses provider lines into groups delimited by blank lines or by a comment that follows an entry.
     *
     * @param list<string> $lines Provider lines.
     *
     * @return list<array{list<string>, list<string>}> `[comments, entries]` pairs; entries are trimmed, and comment
     * blocks without entries are dropped because they add nothing to the merge.
     */
    private static function groups(array $lines): array
    {
        $groups = [];
        $comments = [];
        $entries = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || (str_starts_with($trimmed, '#') && $entries !== [])) {
                if ($entries !== []) {
                    $groups[] = [$comments, $entries];
                }

                $comments = [];
                $entries = [];

                if ($trimmed === '') {
                    continue;
                }
            }

            if (str_starts_with($trimmed, '#')) {
                $comments[] = $line;
            } else {
                $entries[] = $trimmed;
            }
        }

        if ($entries !== []) {
            $groups[] = [$comments, $entries];
        }

        return $groups;
    }

    /**
     * Returns the index of the last line of the consumer block headed by `$comment`.
     *
     * @param list<string> $consumer Consumer lines.
     * @param string $comment Trimmed first comment line of the provider group, matched byte-exact.
     *
     * @return int|null Index of the block's last entry (or last header line), or `null` when the comment is absent.
     */
    private static function locate(array $consumer, string $comment): int|null
    {
        foreach ($consumer as $index => $line) {
            if (trim($line) !== $comment) {
                continue;
            }

            $anchor = $index;
            $lastEntry = null;

            // The scan starts at the header itself, a comment line that leaves the anchor on the header.
            foreach (array_slice($consumer, $index, preserve_keys: true) as $next => $nextLine) {
                $trimmed = trim($nextLine);

                if ($trimmed === '') {
                    break;
                }

                if (str_starts_with($trimmed, '#')) {
                    if ($lastEntry !== null) {
                        break;
                    }

                    $anchor = $next;

                    continue;
                }

                $lastEntry = $next;
            }

            return $lastEntry ?? $anchor;
        }

        return null;
    }
}
