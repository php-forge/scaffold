<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\Merge\LinesTest;

/**
 * Data provider for {@see LinesTest} test cases.
 */
final class LinesProvider
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lines(): iterable
    {
        yield 'empty content' => ['', []];
        yield 'single line without terminator' => ['a', ['a']];
        yield 'terminated lines' => ["a\nb\n", ['a', 'b']];
        yield 'trailing blank line' => ["a\n\n", ['a', '']];
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function segments(): iterable
    {
        yield 'CR line inside an LF line' => ["a\rb\n", [['a', "\r"], ['b', "\n"]]];
        yield 'CR-only lines' => ["a\rb\r", [['a', "\r"], ['b', "\r"]]];
        yield 'CRLF lines' => ["a\r\nb\r\n", [['a', "\r\n"], ['b', "\r\n"]]];
        yield 'empty content' => ['', []];
        yield 'LF lines without final terminator' => ["a\nb", [['a', "\n"], ['b', '']]];
    }
}
