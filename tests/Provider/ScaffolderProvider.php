<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Tests\Functional\ScaffolderTest;

/**
 * Data provider for {@see ScaffolderTest} test cases.
 */
final class ScaffolderProvider
{
    /**
     * @return iterable<string, array{list<string>, string, string}>
     */
    public static function allowedOrders(): iterable
    {
        yield 'override listed last' => [
            ['php-forge/base', 'php-forge/override'],
            'php-forge/override',
            'php-forge/base',
        ];
        yield 'base listed last' => [
            ['php-forge/override', 'php-forge/base'],
            'php-forge/base',
            'php-forge/override',
        ];
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function mergedFiles(): iterable
    {
        yield 'append' => ['append', '*.log', 'vendor/', "vendor/\n\n*.log"];
        yield 'prepend' => ['prepend', 'header', 'existing', "header\nexisting"];
    }

    /**
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function prefixMatches(): iterable
    {
        yield 'case-sensitive, same casing' => ['/Project/vendor/foo', '/Project/', false, true];
        yield 'case-sensitive, other casing' => ['/project/vendor/foo', '/Project/', false, false];
        yield 'case-insensitive, other casing' => ['/project/vendor/foo', '/Project/', true, true];
        yield 'case-insensitive, other path' => ['/elsewhere/vendor/foo', '/Project/', true, false];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function providersWithoutMappings(): iterable
    {
        yield 'no scaffold block' => [[], []];
        yield 'only default-excluded entries' => [
            ['scaffold' => ['copy' => ['.github/linters']]],
            ['.github/linters/actionlint.yml' => 'x'],
        ];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function rootsWithoutProviders(): iterable
    {
        yield 'no scaffold block' => [[], Message::SCAFFOLD_CONFIG_MISSING->getMessage()];
        yield 'no allowed-packages key' => [['scaffold' => []], Message::SCAFFOLD_NO_ALLOWED_PACKAGES->getMessage()];
        yield 'empty allowed-packages' => [
            ['scaffold' => ['allowed-packages' => []]],
            Message::SCAFFOLD_NO_ALLOWED_PACKAGES->getMessage(),
        ];
    }
}
