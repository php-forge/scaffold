<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console;

use function array_column;
use function array_map;
use function array_sum;
use function count;
use function implode;
use function max;
use function rtrim;
use function str_pad;
use function str_repeat;
use function strlen;

/**
 * Renders a plain-text table whose columns are as wide as their longest cell and separated by two spaces.
 */
final class Table
{
    /**
     * Returns the header line, a dash separator as wide as the table, and one line per row.
     *
     * @param list<string> $headers Column headers.
     * @param list<list<string>> $rows Rows with one cell per header.
     *
     * @return list<string> Lines without trailing newlines or trailing spaces.
     */
    public static function render(array $headers, array $rows): array
    {
        $widths = [];

        foreach ($headers as $column => $header) {
            $widths[] = max(array_map(strlen(...), [$header, ...array_column($rows, $column)]));
        }

        $line = static fn(array $cells): string => rtrim(implode('  ', array_map(str_pad(...), $cells, $widths)));

        return [
            $line($headers),
            str_repeat('-', array_sum($widths) + 2 * (count($widths) - 1)),
            ...array_map($line, $rows),
        ];
    }
}
