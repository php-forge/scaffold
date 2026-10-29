<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Merge;

use PHPForge\Scaffold\Merge\Lines;
use PHPForge\Scaffold\Tests\Provider\LinesProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see Lines} splitting content into lines and into segments that keep their terminators.
 *
 * {@see LinesProvider} for test case data providers.
 */
#[Test]
#[Covers(Lines::class)]
final class LinesTest
{
    /**
     * Ensures raw content splits into segments that keep each original terminator.
     *
     * @param list<array{string, string}> $expected Expected `[text, terminator]` segments.
     */
    #[DataProvider(LinesProvider::class . '::segments')]
    public function splitRawKeepsEachTerminator(string $content, array $expected): void
    {
        Assert::same(
            Lines::splitRaw($content),
            $expected,
            'Segments must match.',
        );
    }
    /**
     * Ensures content normalised to `"\n"` splits into its lines, ignoring the last terminator.
     *
     * @param list<string> $expected Expected lines.
     */
    #[DataProvider(LinesProvider::class . '::lines')]
    public function splitYieldsTheLines(string $content, array $expected): void
    {
        Assert::same(
            Lines::split($content),
            $expected,
            'Lines must match.',
        );
    }
}
