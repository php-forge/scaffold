<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Manifest;

use PHPForge\Scaffold\Manifest\{DefaultExcludes, Glob};
use PHPForge\Scaffold\Tests\Provider\DefaultExcludesProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see DefaultExcludes} built-in exclusion patterns.
 *
 * {@see DefaultExcludesProvider} for test case data providers.
 */
#[Test]
#[Covers(DefaultExcludes::class)]
#[Covers(Glob::class)]
final class DefaultExcludesTest
{
    /**
     * Ensures tooling, metadata and artefact paths match at least one default-exclude pattern.
     */
    #[DataProvider(DefaultExcludesProvider::class . '::excludedPaths')]
    public function excludedPathMatchesTheDefaultExcludes(string $path): void
    {
        Assert::true(
            Glob::matchesAny(DefaultExcludes::PATTERNS, $path),
            "'{$path}' must match a default-exclude pattern.",
        );
    }

    /**
     * Ensures project content matches no default-exclude pattern.
     */
    #[DataProvider(DefaultExcludesProvider::class . '::includedPaths')]
    public function includedPathDoesNotMatchTheDefaultExcludes(string $path): void
    {
        Assert::false(
            Glob::matchesAny(DefaultExcludes::PATTERNS, $path),
            "'{$path}' must not match a default-exclude pattern.",
        );
    }

    /**
     * Ensures a directory without a default `<dir>/**` pattern is not pruned.
     */
    #[DataProvider(DefaultExcludesProvider::class . '::nonPrunableDirectories')]
    public function nonPrunableDirectoryIsNotPruned(string $directory): void
    {
        Assert::false(
            Glob::prunes(DefaultExcludes::PATTERNS, $directory),
            "'{$directory}' must not be prunable.",
        );
    }

    /**
     * Ensures a directory with a default `<dir>/**` pattern is pruned.
     */
    #[DataProvider(DefaultExcludesProvider::class . '::prunableDirectories')]
    public function prunableDirectoryIsPruned(string $directory): void
    {
        Assert::true(
            Glob::prunes(DefaultExcludes::PATTERNS, $directory),
            "'{$directory}' must be prunable.",
        );
    }
}
