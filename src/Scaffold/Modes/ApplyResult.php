<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Modes;

/**
 * Represents the outcome of applying a single scaffold file mapping.
 */
final readonly class ApplyResult
{
    public function __construct(
        public ApplyOutcome $outcome,
        public string $newHash,
        public string|null $warning,
    ) {}
}
