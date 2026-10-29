<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Services;

use PHPForge\Scaffold\Services\FileStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Unit tests for {@see FileStatus} listing the states in summary order.
 */
#[Test]
#[Covers(FileStatus::class)]
final class FileStatusTest
{
    public function summaryOrderListsEveryCaseOnceInReportOrder(): void
    {
        Assert::same(
            FileStatus::summaryOrder(),
            [
                FileStatus::Synced,
                FileStatus::Pending,
                FileStatus::Outdated,
                FileStatus::Modified,
                FileStatus::Conflict,
                FileStatus::Missing,
                FileStatus::Orphan,
                FileStatus::Ejected,
                FileStatus::Error,
            ],
            'Summary order must list the states from synced to error.',
        );
        Assert::count(
            FileStatus::cases(),
            9,
            "Every case must be listed by 'summaryOrder()'.",
        );
    }
}
