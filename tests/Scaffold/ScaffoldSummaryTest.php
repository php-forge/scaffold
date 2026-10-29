<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold;

use PHPForge\Scaffold\Scaffold\ScaffoldSummary;
use PHPForge\Scaffold\Services\ReapplySummary;
use PHPForge\Scaffold\Tests\Provider\ScaffoldSummaryProvider;
use PHPForge\Scaffold\Tests\Support\ExpectedSummary;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see ScaffoldSummary} and {@see ReapplySummary} formatting the one-line run summaries.
 *
 * {@see ScaffoldSummaryProvider} for test case data providers.
 */
#[Test]
#[Covers(ScaffoldSummary::class)]
#[Covers(ReapplySummary::class)]
final class ScaffoldSummaryTest
{
    public function formatRendersEvenWhenNothingHappened(): void
    {
        $summary = new ScaffoldSummary();

        Assert::same(
            [$summary->format(), $summary->format('sync')],
            [ExpectedSummary::line(0, 0), ExpectedSummary::withCommand('sync', 0, 0)],
            'Format must always render, with the command name when one is given.',
        );
    }

    #[DataProvider(ScaffoldSummaryProvider::class . '::reapplyLines')]
    public function reapplyLineListsSkipReasons(ReapplySummary $summary, bool $dryRun, string $expected): void
    {
        Assert::same(
            $summary->line($dryRun),
            $expected,
            'Reapply summary line must match.',
        );
    }

    #[DataProvider(ScaffoldSummaryProvider::class . '::scaffoldLines')]
    public function scaffoldLineListsAttentionCountsOnlyWhenNotZero(
        ScaffoldSummary $summary,
        string|null $expected,
    ): void {
        Assert::same(
            $summary->line(),
            $expected,
            'Scaffold summary line must match.',
        );
    }
}
