<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Modes;

/**
 * Represents the result of applying a file mapping: its outcome, the destination hash and an optional warning.
 */
final readonly class ApplyResult
{
    /**
     * @param ApplyOutcome $outcome Whether the destination was written or left unchanged.
     * @param string $newHash Hash of the destination after the operation, or an empty string when a user-modified
     * destination was skipped.
     * @param string|null $warning Message for the user, or `null` when there is nothing to report.
     */
    public function __construct(
        public ApplyOutcome $outcome,
        public string $newHash,
        public string|null $warning,
    ) {}
}
