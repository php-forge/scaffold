<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console;

use function explode;
use function implode;
use function str_starts_with;

/**
 * Colors unified diff lines with raw ANSI escape codes: the first two lines (file headers) in bold, hunk headers in
 * cyan, added lines in green and removed lines in red.
 */
final class DiffColorizer
{
    /**
     * ANSI escape code for bold text.
     */
    private const string BOLD = "\e[1m";
    /**
     * ANSI escape code for cyan text.
     */
    private const string CYAN = "\e[36m";
    /**
     * ANSI escape code for green text.
     */
    private const string GREEN = "\e[32m";
    /**
     * ANSI escape code for red text.
     */
    private const string RED = "\e[31m";
    /**
     * ANSI escape code that resets all text attributes.
     */
    private const string RESET = "\e[0m";

    /**
     * Colors a unified diff whose first two lines are the `---` and `+++` file headers.
     *
     * @param string $diff Unified diff with `"\n"` line separators.
     *
     * @return string Diff with each styled line wrapped in ANSI escape codes; context lines stay unstyled.
     */
    public function colorize(string $diff): string
    {
        $styled = [];

        foreach (explode("\n", $diff) as $index => $line) {
            $styled[] = self::styleLine($line, $index);
        }

        return implode("\n", $styled);
    }

    /**
     * Styles a single diff line according to its position and prefix.
     *
     * @param string $line Diff line without a trailing newline.
     * @param int $index Zero-based position of the line within the diff.
     *
     * @return string Line wrapped in ANSI escape codes, or unchanged for context lines.
     */
    private static function styleLine(string $line, int $index): string
    {
        if ($index < 2) {
            return self::BOLD . $line . self::RESET;
        }

        if (str_starts_with($line, '@@')) {
            return self::CYAN . $line . self::RESET;
        }

        if (str_starts_with($line, '+')) {
            return self::GREEN . $line . self::RESET;
        }

        if (str_starts_with($line, '-')) {
            return self::RED . $line . self::RESET;
        }

        return $line;
    }
}
