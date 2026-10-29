<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Modes;

/**
 * Represents the outcome of applying a file mapping.
 */
enum ApplyOutcome
{
    /**
     * The destination was left unchanged.
     */
    case Skipped;

    /**
     * The destination was created or rewritten.
     */
    case Written;
}
