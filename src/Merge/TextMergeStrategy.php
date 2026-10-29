<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Merge;

use function array_flip;
use function array_map;
use function rtrim;

/**
 * Merges plain text by appending the non-blank provider lines the consumer lacks, compared after right-trim.
 *
 * @phpstan-import-type MergePlan from AbstractLineMergeStrategy
 */
final class TextMergeStrategy extends AbstractLineMergeStrategy
{
    /**
     * Plans one block, appended at the end, with the missing provider lines in provider order.
     *
     * @param list<string> $provider Provider lines.
     * @param list<string> $consumer Consumer lines without terminators.
     *
     * @return MergePlan No insertions, and one block of missing lines, or no block when the consumer has them all.
     */
    protected function plan(array $provider, array $consumer): array
    {
        $present = array_flip(array_map(rtrim(...), $consumer));

        $missing = [];

        foreach ($provider as $line) {
            $line = rtrim($line);

            if ($line === '' || isset($present[$line])) {
                continue;
            }

            $present[$line] = $line;
            $missing[] = $line;
        }

        return [
            'insertAfter' => [],
            'blocks' => $missing === [] ? [] : [$missing],
        ];
    }
}
