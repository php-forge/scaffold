<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Manifest;

use PHPForge\Scaffold\Manifest\Glob;
use PHPForge\Scaffold\Merge\MergeStrategyKind;
use PHPForge\Scaffold\Tests\Provider\GlobProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see Glob} pattern-to-regex conversion, matching semantics and map lookup.
 *
 * {@see GlobProvider} for test case data providers.
 */
#[Test]
#[Covers(Glob::class)]
final class GlobTest
{
    /**
     * Ensures a lookup prefers an exact key, then the first matching glob, and yields `null` without a match.
     *
     * @param array<array-key, MergeStrategyKind> $map Values keyed by glob pattern.
     */
    #[DataProvider(GlobProvider::class . '::lookups')]
    public function lookupReturnsTheValueOfTheWinningPattern(
        array $map,
        string $path,
        MergeStrategyKind|null $expected,
    ): void {
        Assert::same(
            Glob::lookup($map, $path),
            $expected,
            "Lookup of '{$path}' must yield the value of the winning pattern.",
        );
    }

    /**
     * Ensures a pattern matches a path exactly when the glob semantics say so.
     */
    #[DataProvider(GlobProvider::class . '::matches')]
    public function matchesFollowsTheGlobSemantics(string $pattern, string $path, bool $expected): void
    {
        Assert::same(
            Glob::matches($pattern, $path),
            $expected,
            "Match of '{$pattern}' against '{$path}' must be '" . ($expected ? 'true' : 'false') . "'.",
        );
    }

    /**
     * Ensures a pattern compiles to the expected anchored regex.
     */
    #[DataProvider(GlobProvider::class . '::regexes')]
    public function toRegexCompilesTheAnchoredRegex(string $pattern, string $expected): void
    {
        Assert::same(
            Glob::toRegex($pattern),
            $expected,
            "Regex of '{$pattern}' must match.",
        );
    }
}
