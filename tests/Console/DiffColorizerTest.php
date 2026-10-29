<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console;

use PHPForge\Scaffold\Console\DiffColorizer;
use PHPForge\Scaffold\Tests\Provider\DiffColorizerProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see DiffColorizer} ANSI styling of unified diff lines.
 *
 * {@see DiffColorizerProvider} for test case data providers.
 */
#[Test]
#[Covers(DiffColorizer::class)]
final class DiffColorizerTest
{
    /**
     * Ensures each line kind gets its style: bold headers, cyan hunks, green additions, red removals, plain others.
     */
    #[DataProvider(DiffColorizerProvider::class . '::lines')]
    public function stylesEachLineKind(string $diff, string $expected): void
    {
        Assert::same(
            (new DiffColorizer())->colorize($diff),
            $expected,
            'Colorized diff must match the expected styling.',
        );
    }
}
