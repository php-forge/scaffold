<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold;

use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Scaffold\MappingMerger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Unit tests for {@see MappingMerger} applying the last-provider-wins rule per destination.
 */
#[Test]
#[Covers(MappingMerger::class)]
final class MappingMergerTest
{
    public function laterMappingReplacesEarlierAndReportsCrossProviderOverrides(): void
    {
        $merged = [];

        $first = new FileMapping('a.txt', 's', FileMode::Replace, 'p/a', '/a');
        $same = new FileMapping('a.txt', 's2', FileMode::Replace, 'p/a', '/a');
        $other = new FileMapping('a.txt', 's', FileMode::Append, 'p/b', '/b');
        $numeric = new FileMapping('123', 's', FileMode::Replace, 'p/b', '/b');

        Assert::same(
            MappingMerger::merge($merged, [$first, $same]),
            [],
            'Same provider must not be an override.',
        );
        Assert::same(
            MappingMerger::merge($merged, [$other, $numeric]),
            [[$other, $same]],
            'Other provider must be reported as an override pair.',
        );
        Assert::same(
            $merged,
            ['a.txt' => $other, '123' => $numeric],
            'Last mapping must win and an integer-like destination must be kept.',
        );
    }
}
