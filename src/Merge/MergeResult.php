<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Merge;

/**
 * Represents the outcome of a merge.
 */
final readonly class MergeResult
{
    /**
     * @param string $content Merged content; byte-identical to the consumer when `$changed` is `false`.
     * @param bool $changed Whether `$content` differs from the consumer content.
     */
    public function __construct(
        public string $content,
        public bool $changed,
    ) {}
}
