<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Manifest\FileMode;
use PHPForge\Scaffold\Merge\MergeStrategyKind;
use PHPForge\Scaffold\Tests\Manifest\ManifestExpanderTest;

/**
 * Data provider for {@see ManifestExpanderTest} test cases.
 */
final class ManifestExpanderProvider
{
    /**
     * @return iterable<string, array{
     *   list<string>,
     *   list<string|array{from: string, to: string}>,
     *   array<string, FileMode>,
     *   array<string, MergeStrategyKind>,
     *   array{mode: array<string, FileMode>, merge: array<string, MergeStrategyKind|null>},
     * }>
     */
    public static function attributes(): iterable
    {
        yield 'merge strategy by exact path, then glob, then null' => [
            ['files/.editorconfig', 'files/conf/app.cfg', 'files/conf/db.cfg', 'files/.gitignore'],
            [['from' => 'files', 'to' => '.']],
            [],
            [
                'conf/*' => MergeStrategyKind::Text,
                'conf/app.cfg' => MergeStrategyKind::Ini,
                '.editorconfig' => MergeStrategyKind::ListFile,
            ],
            [
                'mode' => [
                    '.editorconfig' => FileMode::Replace,
                    '.gitignore' => FileMode::Replace,
                    'conf/app.cfg' => FileMode::Replace,
                    'conf/db.cfg' => FileMode::Replace,
                ],
                'merge' => [
                    '.editorconfig' => MergeStrategyKind::ListFile,
                    '.gitignore' => null,
                    'conf/app.cfg' => MergeStrategyKind::Ini,
                    'conf/db.cfg' => MergeStrategyKind::Text,
                ],
            ],
        ];
        yield 'mode by exact path declared after a matching glob' => [
            ['config/params.php'],
            ['config/params.php'],
            ['config/*.php' => FileMode::Preserve, 'config/params.php' => FileMode::Replace],
            [],
            ['mode' => ['config/params.php' => FileMode::Replace], 'merge' => ['config/params.php' => null]],
        ];
        yield 'mode by exact path, then glob, then replace' => [
            ['config/web.php', 'config/params.php', 'src/controllers/SiteController.php'],
            ['config', 'src'],
            ['config/params.php' => FileMode::Preserve, 'config/*.php' => FileMode::Append],
            [],
            [
                'mode' => [
                    'config/params.php' => FileMode::Preserve,
                    'config/web.php' => FileMode::Append,
                    'src/controllers/SiteController.php' => FileMode::Replace,
                ],
                'merge' => [
                    'config/params.php' => null,
                    'config/web.php' => null,
                    'src/controllers/SiteController.php' => null,
                ],
            ],
        ];
    }

    /**
     * @return iterable<string, array{
     *   list<string>,
     *   list<string|array{from: string, to: string}>,
     *   list<string>,
     *   array<string, string>,
     * }>
     */
    public static function copyLists(): iterable
    {
        yield 'default excludes in a walk of the root' => [
            ['src/controllers/SiteController.php', 'src/tests/SomeTest.php', 'composer.json', 'runtime/cache/x.log'],
            ['.'],
            [],
            [
                'src/controllers/SiteController.php' => 'src/controllers/SiteController.php',
                'src/tests/SomeTest.php' => 'src/tests/SomeTest.php',
            ],
        ];
        yield 'directory entry with a trailing separator and an exclude' => [
            ['config/keep.txt', 'config/skip.txt'],
            ['config/'],
            ['config/skip.txt'],
            ['config/keep.txt' => 'config/keep.txt'],
        ];
        yield 'directory entry with a from-to remap' => [
            ['metadata/.github/linters/actionlint.yml', 'metadata/.github/linters/.markdown-lint.yml'],
            [['from' => 'metadata/.github', 'to' => '.github']],
            [],
            [
                '.github/linters/.markdown-lint.yml' => 'metadata/.github/linters/.markdown-lint.yml',
                '.github/linters/actionlint.yml' => 'metadata/.github/linters/actionlint.yml',
            ],
        ];
        yield 'every file of a directory entry' => [
            ['src/controllers/SiteController.php', 'src/models/User.php'],
            ['src'],
            [],
            [
                'src/controllers/SiteController.php' => 'src/controllers/SiteController.php',
                'src/models/User.php' => 'src/models/User.php',
            ],
        ];
        yield 'exclude glob matching several files' => [
            ['src/a.txt', 'src/b.txt', 'src/c.php'],
            ['src'],
            ['src/*.txt'],
            ['src/c.php' => 'src/c.php'],
        ];
        yield 'explicit file under a default-excluded directory' => [
            ['runtime/.gitignore'],
            ['runtime/.gitignore'],
            [],
            ['runtime/.gitignore' => 'runtime/.gitignore'],
        ];
        yield 'file entry before a walk that reaches it again' => [
            ['a.php', 'b.php', 'c.php'],
            ['a.php', '.'],
            [],
            ['a.php' => 'a.php', 'b.php' => 'b.php', 'c.php' => 'c.php'],
        ];
        yield 'file entry with a from-to remap' => [
            ['metadata/.editorconfig'],
            [['from' => 'metadata/.editorconfig', 'to' => '.editorconfig']],
            [],
            ['.editorconfig' => 'metadata/.editorconfig'],
        ];
        yield 'file named like an excluded directory' => [
            ['secrets', 'vault/secrets/key.txt'],
            ['.'],
            ['secrets/**', 'vault/secrets/**'],
            ['secrets' => 'secrets'],
        ];
        yield 'file reached by a file and a directory entry' => [['yii'], ['yii', '.'], [], ['yii' => 'yii']];
        yield 'later entry overrides an earlier one in place' => [
            ['first/app.php', 'second/app.php', 'dir/app.php', 'dir/other.php'],
            [
                ['from' => 'first/app.php', 'to' => 'app.php'],
                ['from' => 'dir', 'to' => '.'],
                ['from' => 'second/app.php', 'to' => 'app.php'],
            ],
            [],
            ['app.php' => 'second/app.php', 'other.php' => 'dir/other.php'],
        ];
        yield 'next directory entry after a walk' => [
            ['dir1/a.txt', 'dir2/b.txt'],
            ['dir1', 'dir2'],
            [],
            ['dir1/a.txt' => 'dir1/a.txt', 'dir2/b.txt' => 'dir2/b.txt'],
        ];
        yield 'repeated file entry' => [
            ['yii', 'other.txt'],
            ['yii', 'yii', 'other.txt'],
            [],
            ['yii' => 'yii', 'other.txt' => 'other.txt'],
        ];
        yield 'user exclude on top of the defaults' => [
            ['src/controllers/SiteController.php', 'src/controllers/Disabled.php'],
            ['src'],
            ['src/controllers/Disabled.php'],
            ['src/controllers/SiteController.php' => 'src/controllers/SiteController.php'],
        ];
        yield 'walk results in sorted order' => [
            ['mmm.php', 'zzz.php', 'aaa.php'],
            ['.'],
            [],
            ['aaa.php' => 'aaa.php', 'mmm.php' => 'mmm.php', 'zzz.php' => 'zzz.php'],
        ];
    }
}
