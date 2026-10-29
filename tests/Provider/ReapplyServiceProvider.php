<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Tests\Services\{ReapplyDryRunTest, ReapplyServiceTest};
use PHPForge\Scaffold\Tests\Support\ExpectedSummary;

/**
 * Data provider for {@see ReapplyServiceTest} and {@see ReapplyDryRunTest} test cases.
 */
final class ReapplyServiceProvider
{
    /**
     * @return iterable<string, array{string, bool, int, string}>
     */
    public static function dryRunExitCodes(): iterable
    {
        $unchanged = Message::DIFF_NO_CHANGES->getMessage('a.txt') . PHP_EOL
            . ExpectedSummary::reapplyDryRun(0, 1, 0, 0) . PHP_EOL;
        $changed = Message::DIFF_PREVIEW_HEADER->getMessage('a.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1 @@\n-v1\n+v2" . PHP_EOL
            . ExpectedSummary::reapplyDryRun(1, 0, 0, 0) . PHP_EOL;

        yield 'unchanged with exit code' => ["v1\n", true, 0, $unchanged];
        yield 'changed with exit code' => ["v2\n", true, 1, $changed];
        yield 'changed without exit code' => ["v2\n", false, 0, $changed];
    }

    /**
     * @return iterable<string, array{string, string}> File and provider filters that match no tracked file.
     */
    public static function unmatchedFilters(): iterable
    {
        yield 'file filter' => ['missing.php', ''];
        yield 'provider filter' => ['', 'p/missing'];
        yield 'file and provider filters' => ['missing.php', 'p/missing'];
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string, string}>
     */
    public static function unsafeEntries(): iterable
    {
        yield 'unsafe destination' => ['../escape.php', [], 'destination', '../escape.php'];
        yield 'unsafe source' => ['a.txt', ['source' => '../escape.txt'], 'source', '../escape.txt'];
    }
}
