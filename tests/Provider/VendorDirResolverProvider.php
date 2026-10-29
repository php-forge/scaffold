<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\Config\VendorDirResolverTest;

/**
 * Data provider for {@see VendorDirResolverTest} test cases.
 */
final class VendorDirResolverProvider
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function environmentValues(): iterable
    {
        yield 'absolute path with trailing separator' => ['/opt/shared-vendor/', '/opt/shared-vendor'];
        yield 'absolute POSIX path ending in a drive root' => ['/foo/C:/', '/foo/C:'];
        yield 'relative path with a drive letter in the middle' => ['prefix/C:/suffix', '{root}/prefix/C:/suffix'];
        yield 'relative path' => ['custom-vendor', '{root}/custom-vendor'];
        yield 'Windows absolute path' => ['C:\\opt\\vendor', 'C:\\opt\\vendor'];
        yield 'Windows drive root with backslash' => ['C:\\', 'C:\\'];
        yield 'Windows drive root with forward slash' => ['D:/', 'D:/'];
        yield 'Windows drive-relative path' => ['C:vendor', '{root}/C:vendor'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function manifestSettings(): iterable
    {
        yield 'absolute vendor-dir' => [
            ['config' => ['vendor-dir' => '/opt/shared-vendor']],
            '',
            '/opt/shared-vendor',
        ];
        yield 'relative vendor-dir' => [
            ['config' => ['vendor-dir' => 'third-party']],
            '',
            '{root}/third-party',
        ];
        yield 'missing vendor-dir' => [
            ['name' => 'test/no-vendor-dir-config'],
            '',
            '{root}/vendor',
        ];
        yield 'empty vendor-dir' => [
            ['config' => ['vendor-dir' => '']],
            '',
            '{root}/vendor',
        ];
        yield 'trailing separators on root and vendor-dir' => [
            ['config' => ['vendor-dir' => 'custom/']],
            '/',
            '{root}/custom',
        ];
    }
}
