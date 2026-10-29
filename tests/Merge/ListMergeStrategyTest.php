<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Merge;

use PHPForge\Scaffold\Merge\{AbstractLineMergeStrategy, Lines, ListMergeStrategy, MergeResult};
use PHPForge\Scaffold\Tests\Provider\MergeStrategyProvider;
use PHPForge\Scaffold\Tests\Support\MergeAssertionsTrait;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see ListMergeStrategy} entry-based merging of ignore-style list files.
 *
 * {@see MergeStrategyProvider} for test case data providers.
 */
#[Test]
#[Covers(ListMergeStrategy::class)]
#[Covers(AbstractLineMergeStrategy::class)]
#[Covers(Lines::class)]
#[Covers(MergeResult::class)]
final class ListMergeStrategyTest
{
    use MergeAssertionsTrait;

    /**
     * Ensures the merge yields the expected bytes, keeps every consumer line in order and is idempotent.
     */
    #[DataProvider(MergeStrategyProvider::class . '::listCases')]
    public function mergeYieldsExpectedContent(string $provider, string $consumer, string $expected): void
    {
        self::assertGoldenMerge(
            new ListMergeStrategy(),
            $provider,
            $consumer,
            $expected,
        );
    }
}
