<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\Console\ApplicationRunTest;

/**
 * Data provider for {@see ApplicationRunTest} test cases.
 */
final class ApplicationRunProvider
{
    /**
     * @return iterable<string, array{array<string, bool|string>}>
     */
    public static function commandsReadingTheLock(): iterable
    {
        yield 'diff' => [['command' => 'diff', '--all' => true]];
        yield 'eject' => [['command' => 'eject', 'file' => 'a.txt']];
        yield 'providers' => [['command' => 'providers']];
        yield 'prune' => [['command' => 'prune']];
        yield 'reapply' => [['command' => 'reapply']];
        yield 'status' => [['command' => 'status']];
        yield 'sync' => [['command' => 'sync']];
    }

    /**
     * @return iterable<string, array{array<string, bool|string>, int}>
     */
    public static function commandsSkippingADeniedProvider(): iterable
    {
        yield 'diff all' => [['command' => 'diff', '--all' => true], 1];
        yield 'diff file' => [['command' => 'diff', 'file' => 'd.txt'], 1];
        yield 'reapply' => [['command' => 'reapply'], 1];
        yield 'reapply force' => [['command' => 'reapply', '--force' => true], 1];
        yield 'status' => [['command' => 'status'], 0];
    }

    /**
     * @return iterable<string, array{string, array<string, bool|string>}>
     */
    public static function projectRootStarts(): iterable
    {
        yield 'nested current directory' => ['{root}/src/deep', ['command' => 'status']];
        yield 'short working directory option' => ['/', ['command' => 'status', '-d' => '{root}']];
        yield 'working directory option' => ['/', ['command' => 'status', '--working-dir' => '{root}/src']];
    }
}
