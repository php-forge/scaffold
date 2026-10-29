<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Tests\Security\PathValidatorTest;

/**
 * Data provider for {@see PathValidatorTest} test cases.
 */
final class PathValidatorProvider
{
    /**
     * Number of chained symbolic links that exceeds the hop limit of the dangling link walk.
     */
    private const int TOO_MANY_HOPS = 41;

    /**
     * @return iterable<string, array{'destination'|'source', string, list<string>, array<string, string>}>
     */
    public static function acceptedPaths(): iterable
    {
        yield 'relative destination' => [
            'destination',
            'config/params.php',
            [],
            [],
        ];
        yield 'nested relative destination' => [
            'destination',
            'resources/js/Pages/Home.vue',
            [],
            [],
        ];
        yield 'dot file' => [
            'destination',
            '.gitignore',
            [],
            [],
        ];
        yield 'empty destination' => [
            'destination',
            '',
            [],
            [],
        ];
        yield 'destination with trailing separator' => [
            'destination',
            'config/',
            [],
            [],
        ];
        yield 'lock file name below a directory' => [
            'destination',
            'config/scaffold-lock.json',
            [],
            [],
        ];
        yield 'deep missing destination' => [
            'destination',
            'a/b/c/d/does-not-exist.txt',
            [],
            [],
        ];
        yield 'missing file in an existing directory' => [
            'destination',
            'sub/missing-file.txt',
            ['root/sub'],
            [],
        ];
        yield 'dangling link to a missing file inside the root' => [
            'destination',
            'link.txt',
            ['root/real'],
            ['root/link.txt' => 'real/missing.txt'],
        ];
        yield 'link looping back to the root' => [
            'destination',
            'loop/missing-file.txt',
            [],
            ['root/loop' => '{tmp}/root'],
        ];
        yield 'relative source' => [
            'source',
            'stubs/params.php',
            [],
            [],
        ];
        yield 'empty source' => [
            'source',
            '',
            [],
            [],
        ];
        yield 'dangling link chain inside the root' => [
            'source',
            'first',
            [],
            ['root/first' => '{tmp}/root/second', 'root/second' => 'missing.txt'],
        ];
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function paths(): iterable
    {
        yield 'relative path' => ['config/app.php', false, false];
        yield 'dot file' => ['.gitignore', false, false];
        yield 'POSIX absolute' => ['/etc/passwd', true, false];
        yield 'backslash absolute' => ['\\server\\share', true, false];
        yield 'drive absolute' => ['C:\\Windows\\file', true, false];
        yield 'drive relative' => ['c:file', true, false];
        yield 'drive letter mid-path' => ['nested/C:/file.txt', false, false];
        yield 'traversal at start' => ['../etc/passwd', false, true];
        yield 'traversal in the middle' => ['config/../../etc/passwd', false, true];
        yield 'backslash traversal' => ['config\\..\\etc', false, true];
        yield 'trailing traversal' => ['config/..', false, true];
        yield 'double dot inside a name' => ['foo..bar', false, false];
        yield 'absolute with traversal' => ['/a/../b', true, true];
    }

    /**
     * @return iterable<string, array{'destination'|'source', string, list<string>, array<string, string>, string}>
     */
    public static function rejectedPaths(): iterable
    {
        yield 'absolute destination' => [
            'destination',
            '/etc/passwd',
            [],
            [],
            Message::PATH_ABSOLUTE->getMessage('destination', '/etc/passwd'),
        ];
        yield 'destination with traversal' => [
            'destination',
            '../../../etc/passwd',
            [],
            [],
            Message::PATH_TRAVERSAL->getMessage('destination', '../../../etc/passwd'),
        ];
        yield 'absolute source' => [
            'source',
            '/etc/shadow',
            [],
            [],
            Message::PATH_ABSOLUTE->getMessage('source', '/etc/shadow'),
        ];
        yield 'source with traversal' => [
            'source',
            '../stubs/params.php',
            [],
            [],
            Message::PATH_TRAVERSAL->getMessage('source', '../stubs/params.php'),
        ];

        foreach (
            [
                'scaffold-lock.json',
                './scaffold-lock.json',
                'scaffold-lock.json/nested.txt',
                'SCAFFOLD-LOCK.JSON',
                'scaffold-lock.json\\nested.txt',
            ] as $reserved) {
            yield "reserved destination '{$reserved}'" => [
                'destination',
                $reserved,
                [],
                [],
                Message::PATH_RESERVED->getMessage($reserved),
            ];
        }

        yield 'destination link escaping the root' => [
            'destination',
            'escape',
            ['outside'],
            ['root/escape' => '{tmp}/outside'],
            Message::PATH_SYMLINK_ESCAPE->getMessage('Destination', 'escape'),
        ];
        yield 'destination link to a directory sharing the root prefix' => [
            'destination',
            'link',
            ['rootsibling'],
            ['root/link' => '{tmp}/rootsibling'],
            Message::PATH_SYMLINK_ESCAPE->getMessage('Destination', 'link'),
        ];
        yield 'missing destination below a link escaping the root' => [
            'destination',
            'escape/missing/file.txt',
            ['outside'],
            ['root/escape' => '{tmp}/outside'],
            Message::PATH_SYMLINK_ESCAPE->getMessage('Destination', 'escape/missing/file.txt'),
        ];
        yield 'missing destination below a link to a directory sharing the root prefix' => [
            'destination',
            'link/missing-leaf.txt',
            ['rootsibling'],
            ['root/link' => '{tmp}/rootsibling'],
            Message::PATH_SYMLINK_ESCAPE->getMessage('Destination', 'link/missing-leaf.txt'),
        ];
        yield 'dangling destination link escaping the root' => [
            'destination',
            'rep.txt',
            ['outside'],
            ['root/rep.txt' => '{tmp}/outside/OUTSIDE_TARGET.txt'],
            Message::PATH_SYMLINK_ESCAPE->getMessage('Destination', 'rep.txt'),
        ];
        yield 'dangling destination link with trailing separator escaping the root' => [
            'destination',
            'rep.txt/',
            ['outside'],
            ['root/rep.txt' => '{tmp}/outside/OUTSIDE_TARGET.txt'],
            Message::PATH_SYMLINK_ESCAPE->getMessage('Destination', 'rep.txt/'),
        ];
        yield 'dangling destination link chain escaping the root' => [
            'destination',
            'first',
            ['outside'],
            ['root/first' => 'second', 'root/second' => '{tmp}/outside/target.txt'],
            Message::PATH_SYMLINK_ESCAPE->getMessage('Destination', 'first'),
        ];
        yield 'dangling destination link into a missing directory' => [
            'destination',
            'link.txt',
            [],
            ['root/link.txt' => 'missing-dir/target.txt'],
            Message::PATH_SYMLINK_UNRESOLVABLE->getMessage('Destination', 'link.txt'),
        ];
        yield 'dangling destination link chain longer than the hop limit' => [
            'destination',
            'link0',
            [],
            self::linkChain(),
            Message::PATH_SYMLINK_UNRESOLVABLE->getMessage('Destination', 'link0'),
        ];
        yield 'source link escaping the root' => [
            'source',
            'escape',
            ['outside'],
            ['root/escape' => '{tmp}/outside'],
            Message::PATH_SYMLINK_ESCAPE->getMessage('Source', 'escape'),
        ];
        yield 'dangling source link escaping the root' => [
            'source',
            'stub.txt',
            [],
            ['root/stub.txt' => '{tmp}/secret.txt'],
            Message::PATH_SYMLINK_ESCAPE->getMessage('Source', 'stub.txt'),
        ];
    }

    /**
     * Returns a chain of dangling links `link0` -> `link1` -> ... ending in a missing file, one hop over the limit.
     *
     * @return array<string, string> Link targets keyed by link path.
     */
    private static function linkChain(): array
    {
        $links = [];

        for ($hop = 0; $hop < self::TOO_MANY_HOPS; $hop++) {
            $links["root/link{$hop}"] = $hop + 1 === self::TOO_MANY_HOPS ? 'missing.txt' : 'link' . ($hop + 1);
        }

        return $links;
    }
}
