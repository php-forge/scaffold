<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\Console\DiffColorizerTest;

/**
 * Data provider for {@see DiffColorizerTest} test cases.
 */
final class DiffColorizerProvider
{
    /**
     * File headers of every diff.
     */
    private const string HEADERS = "--- a/config/params.php\n+++ b/config/params.php\n";
    /**
     * Styled file headers of every diff.
     */
    private const string STYLED_HEADERS = "\e[1m--- a/config/params.php\e[0m\n\e[1m+++ b/config/params.php\e[0m\n";

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lines(): iterable
    {
        yield 'added line in green' => [
            self::HEADERS . '+new',
            self::STYLED_HEADERS . "\e[32m+new\e[0m",
        ];
        yield 'body line starting with dashes in red' => [
            self::HEADERS . '--- section marker',
            self::STYLED_HEADERS . "\e[31m--- section marker\e[0m",
        ];
        yield 'context line unstyled' => [
            self::HEADERS . ' context',
            self::STYLED_HEADERS . ' context',
        ];
        yield 'file headers in bold' => [
            self::HEADERS,
            self::STYLED_HEADERS,
        ];
        yield 'hunk header in cyan' => [
            self::HEADERS . '@@ -1,2 +1,2 @@',
            self::STYLED_HEADERS . "\e[36m@@ -1,2 +1,2 @@\e[0m",
        ];
        yield 'no newline marker unstyled' => [
            self::HEADERS . '\\ No newline at end of file',
            self::STYLED_HEADERS . '\\ No newline at end of file',
        ];
        yield 'removed line in red' => [
            self::HEADERS . '-old',
            self::STYLED_HEADERS . "\e[31m-old\e[0m",
        ];
    }
}
