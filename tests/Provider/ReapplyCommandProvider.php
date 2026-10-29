<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\Console\Command\ReapplyCommandTest;

/**
 * Data provider for {@see ReapplyCommandTest} test cases.
 */
final class ReapplyCommandProvider
{
    /**
     * @return iterable<string, array{array<string, bool|string>, int, string}>
     */
    public static function inputs(): iterable
    {
        yield 'dry run with force' => [['--dry-run' => true, '--force' => true], 0, "user-edited\n"];
        yield 'file with force' => [['file' => 'config/params.php', '--force' => true], 0, "original\n"];
        yield 'file without force' => [['file' => 'config/params.php'], 0, "user-edited\n"];
        yield 'matching provider with force' => [['--provider' => 'pkg/name', '--force' => true], 0, "original\n"];
        yield 'other provider with force' => [['--provider' => 'pkg/other', '--force' => true], 1, "user-edited\n"];
        yield 'untracked file' => [['file' => 'missing.php', '--force' => true], 1, "user-edited\n"];
    }
}
