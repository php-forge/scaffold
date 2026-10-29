<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\Console\Command\PruneCommandTest;

/**
 * Data provider for {@see PruneCommandTest} test cases.
 */
final class PruneCommandProvider
{
    /**
     * @return iterable<string, array{array<string, bool>, int, array{'old.txt': bool, 'edited.txt': bool}}>
     */
    public static function options(): iterable
    {
        yield 'preview' => [
            [],
            0,
            ['old.txt' => true, 'edited.txt' => true],
        ];
        yield 'preview with delete modified' => [
            ['--delete-modified' => true],
            0,
            ['old.txt' => true, 'edited.txt' => true],
        ];
        yield 'preview with exit code' => [
            ['--exit-code' => true],
            1,
            ['old.txt' => true, 'edited.txt' => true],
        ];
        yield 'force' => [
            ['--force' => true],
            0,
            ['old.txt' => false, 'edited.txt' => true],
        ];
        yield 'force with delete modified' => [
            ['--force' => true, '--delete-modified' => true],
            0,
            ['old.txt' => false, 'edited.txt' => false],
        ];
    }
}
