<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Tests\Scaffold\PathResolverTest;

/**
 * Data provider for {@see PathResolverTest} test cases.
 */
final class PathResolverProvider
{
    /**
     * @return iterable<string, array{'destination'|'source', string, string, string}>
     */
    public static function joins(): iterable
    {
        yield 'destination with leading separator' => [
            'destination',
            '/tmp/foo',
            '/bar',
            '/tmp/foo/bar',
        ];
        yield 'destination with leading backslash' => [
            'destination',
            '/tmp/foo',
            '\\bar',
            '/tmp/foo/bar',
        ];
        yield 'project root with trailing separator' => [
            'destination',
            '/tmp/foo/',
            'bar',
            '/tmp/foo/bar',
        ];
        yield 'project root with trailing backslash' => [
            'destination',
            '/tmp/foo\\',
            'bar',
            '/tmp/foo/bar',
        ];
        yield 'source with leading separator' => [
            'source',
            '/tmp/provider',
            '/file.txt',
            '/tmp/provider/file.txt',
        ];
        yield 'source with leading backslash' => [
            'source',
            '/tmp/provider',
            '\\file.txt',
            '/tmp/provider/file.txt',
        ];
        yield 'provider path with trailing separator' => [
            'source',
            '/tmp/provider/',
            'file.txt',
            '/tmp/provider/file.txt',
        ];
        yield 'provider path with trailing backslash' => [
            'source',
            '/tmp/provider\\',
            'file.txt',
            '/tmp/provider/file.txt',
        ];
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function permissions(): iterable
    {
        yield 'permissive umask keeps the executable bit' => [0o755, 0o644, 0o022, 0o755];
        yield 'restrictive umask drops the group and other bits' => [0o755, 0o600, 0o077, 0o700];
        yield 'zero umask copies the source bits exactly' => [0o640, 0o600, 0o000, 0o640];
    }

    /**
     * @return iterable<string, array{list<string>, string, mixed, string, string, string|null}>
     */
    public static function providerRoots(): iterable
    {
        $outside = Message::SCAFFOLD_PROVIDER_ROOT_OUTSIDE_VENDOR->getMessage('pkg/name');

        yield 'lock entry missing' => [
            [],
            '{tmp}',
            null,
            '',
            '{real}/pkg/name',
            null,
        ];
        yield 'lock entry not an array' => [
            [],
            '{tmp}',
            'vendor/pkg/name',
            '',
            '{real}/pkg/name',
            null,
        ];
        yield 'lock path not a string' => [
            [],
            '{tmp}',
            ['path' => 1],
            '',
            '{real}/pkg/name',
            null,
        ];
        yield 'lock path equal to the vendor directory' => [
            [],
            '{tmp}',
            ['path' => '{tmp}'],
            '',
            '{real}',
            null,
        ];
        yield 'absolute lock path inside vendor' => [
            ['pkg/name'],
            '{tmp}',
            ['path' => '{tmp}/pkg/name'],
            '',
            '{real}/pkg/name',
            null,
        ];
        yield 'absolute lock path inside vendor with a project root' => [
            ['vendor/pkg/name'],
            '{tmp}/vendor',
            ['path' => '{real}/vendor/pkg/name'],
            '{tmp}/unrelated-project-root',
            '{real}/vendor/pkg/name',
            null,
        ];
        yield 'relative lock path expanded against the project root' => [
            ['vendor/pkg/name'],
            '{tmp}/vendor',
            ['path' => 'vendor/pkg/name'],
            '{tmp}',
            '{real}/vendor/pkg/name',
            null,
        ];
        yield 'project root with trailing separator' => [
            ['proj/vendor'],
            '{real}/proj/vendor',
            ['path' => 'vendor/pkg/name-missing'],
            '{real}/proj/',
            '{real}/proj/vendor/pkg/name-missing',
            null,
        ];
        yield 'lock path escaping vendor' => [
            ['pkg/name'],
            '{tmp}',
            ['path' => '/etc'],
            '',
            '{real}/pkg/name',
            $outside,
        ];
        yield 'lock path sharing a string prefix with vendor' => [
            ['vendor', 'vendorsibling'],
            '{tmp}/vendor',
            ['path' => '{tmp}/vendorsibling'],
            '',
            '{real}/vendor/pkg/name',
            $outside,
        ];
    }
}
