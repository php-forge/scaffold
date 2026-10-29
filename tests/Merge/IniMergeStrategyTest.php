<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Merge;

use PHPForge\Scaffold\Merge\{AbstractLineMergeStrategy, IniMergeStrategy, Lines, MergeResult};
use PHPForge\Scaffold\Tests\Provider\MergeStrategyProvider;
use PHPForge\Scaffold\Tests\Support\MergeAssertionsTrait;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see IniMergeStrategy} section-aware merging of EditorConfig and INI files.
 *
 * {@see MergeStrategyProvider} for test case data providers.
 */
#[Test]
#[Covers(IniMergeStrategy::class)]
#[Covers(AbstractLineMergeStrategy::class)]
#[Covers(Lines::class)]
#[Covers(MergeResult::class)]
final class IniMergeStrategyTest
{
    use MergeAssertionsTrait;

    /**
     * Ensures the merge yields the expected bytes, keeps every consumer line in order and is idempotent.
     */
    #[DataProvider(MergeStrategyProvider::class . '::iniCases')]
    public function mergeYieldsExpectedContent(string $provider, string $consumer, string $expected): void
    {
        self::assertGoldenMerge(
            new IniMergeStrategy(),
            $provider,
            $consumer,
            $expected,
        );
    }
}
