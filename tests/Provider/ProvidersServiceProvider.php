<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Tests\Services\ProvidersServiceTest;

/**
 * Data provider for {@see ProvidersServiceTest} test cases.
 */
final class ProvidersServiceProvider
{
    /**
     * @return iterable<string, array{array<string, string>, array<string, string>, array<string, int>}>
     */
    public static function counts(): iterable
    {
        yield 'empty lock' => [
            [],
            [],
            [],
        ];
        yield 'files aggregated per provider' => [
            [],
            ['a.txt' => 'pkg/a', 'b.txt' => 'pkg/a', 'c.txt' => 'pkg/b'],
            ['pkg/a' => 2, 'pkg/b' => 1],
        ];
        yield 'provider metadata without files' => [
            ['pkg/with-files' => '0.1.0', 'pkg/zero-files' => '0.1.0'],
            ['a.txt' => 'pkg/with-files'],
            ['pkg/with-files' => 1, 'pkg/zero-files' => 0],
        ];
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, string>, string}>
     */
    public static function tables(): iterable
    {
        yield 'no providers' => [
            [],
            [],
            Message::PROVIDERS_NONE->getMessage() . PHP_EOL,
        ];
        yield 'one provider' => [
            [],
            ['a.txt' => 'pkg/a'],
            'Provider  Files' . PHP_EOL
            . '---------------' . PHP_EOL
            . 'pkg/a     1' . PHP_EOL,
        ];
        yield 'columns as wide as their longest cell' => [
            ['pkg/zero-files' => '0.1.0'],
            ['a.txt' => 'pkg/a', 'b.txt' => 'pkg/a'],
            'Provider        Files' . PHP_EOL
            . '---------------------' . PHP_EOL
            . 'pkg/zero-files  0' . PHP_EOL
            . 'pkg/a           2' . PHP_EOL,
        ];
    }
}
