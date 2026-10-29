<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console;

use PHPForge\Scaffold\Console\Table;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Unit tests for {@see Table} rendering plain-text tables.
 */
#[Test]
#[Covers(Table::class)]
final class TableTest
{
    /**
     * Ensures columns are as wide as their longest cell, separated by two spaces, without trailing spaces.
     */
    public function rendersPaddedColumnsWithoutTrailingSpaces(): void
    {
        Assert::same(
            Table::render(['File', 'Status'], [['a.txt', 'ok'], ['long-name.txt', 'modified']]),
            [
                'File           Status',
                '-----------------------',
                'a.txt          ok',
                'long-name.txt  modified',
            ],
            'Lines must match.',
        );
    }
}
