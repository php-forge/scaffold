<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Manifest\FileMode;
use PHPForge\Scaffold\Merge\MergeStrategyKind;
use PHPForge\Scaffold\Tests\Manifest\ManifestSchemaTest;

/**
 * Data provider for {@see ManifestSchemaTest} test cases.
 */
final class ManifestSchemaProvider
{
    /**
     * @return iterable<string, array{array<mixed>, bool, string}>
     */
    public static function invalidManifests(): iterable
    {
        $external = 'copy, exclude, merge, modes';
        $inline = 'allowed-packages, auto, copy, exclude, manifest, merge, modes';

        yield 'consumer key in manifest body' => [
            ['copy' => ['src'], 'auto' => false],
            false,
            Message::MANIFEST_KEY_UNKNOWN->getMessage('auto', $external),
        ];
        yield 'excludes typo' => [
            ['copy' => ['src'], 'excludes' => ['a']],
            false,
            Message::MANIFEST_KEY_UNKNOWN->getMessage('excludes', $external),
        ];
        yield 'integer-like unknown key' => [
            ['copy' => ['src'], 0 => 'x'],
            false,
            Message::MANIFEST_KEY_UNKNOWN->getMessage('0', $external),
        ];
        yield 'legacy file-mapping' => [
            ['copy' => ['src'], 'file-mapping' => []],
            false,
            Message::MANIFEST_KEY_UNKNOWN->getMessage('file-mapping', $external),
        ];
        yield 'mode typo' => [
            ['copy' => ['src'], 'mode' => ['a' => 'append']],
            false,
            Message::MANIFEST_KEY_UNKNOWN->getMessage('mode', $external),
        ];
        yield 'unknown key in inline block' => [
            ['copy' => ['src'], 'allowed-package' => []],
            true,
            Message::MANIFEST_KEY_UNKNOWN->getMessage('allowed-package', $inline),
        ];
        yield 'copy absolute entry' => [
            ['copy' => ['/etc']],
            false,
            Message::MANIFEST_ENTRY_ABSOLUTE->getMessage('copy', '/etc'),
        ];
        yield 'copy absolute to' => [
            ['copy' => [['from' => 'metadata/foo', 'to' => '/etc/foo']]],
            false,
            Message::MANIFEST_ENTRY_ABSOLUTE->getMessage('copy.to', '/etc/foo'),
        ];
        yield 'copy associative object' => [
            ['copy' => ['from' => 'metadata/.gitignore', 'to' => '.gitignore']],
            false,
            Message::MANIFEST_COPY_NOT_LIST->getMessage(),
        ];
        yield 'copy empty entry' => [
            ['copy' => ['']],
            false,
            Message::MANIFEST_ENTRY_EMPTY->getMessage('copy'),
        ];
        yield 'copy empty list' => [
            ['copy' => []],
            false,
            Message::MANIFEST_COPY_EMPTY->getMessage(),
        ];
        yield 'copy entry neither string nor object' => [
            ['copy' => [42]],
            false,
            Message::MANIFEST_COPY_ENTRY_INVALID->getMessage(),
        ];
        yield 'copy from traversal' => [
            ['copy' => [['from' => '../escape', 'to' => 'escape']]],
            false,
            Message::MANIFEST_ENTRY_TRAVERSAL->getMessage('copy.from', '../escape'),
        ];
        yield 'copy missing' => [
            [],
            false,
            Message::MANIFEST_COPY_MISSING->getMessage(),
        ];
        yield 'copy not array' => [
            ['copy' => 'src'],
            false,
            Message::MANIFEST_COPY_MISSING->getMessage(),
        ];
        yield 'copy traversal entry' => [
            ['copy' => ['../escape']],
            false,
            Message::MANIFEST_ENTRY_TRAVERSAL->getMessage('copy', '../escape'),
        ];
        yield 'exclude not array' => [
            ['copy' => ['src'], 'exclude' => 'not-an-array'],
            false,
            Message::MANIFEST_EXCLUDE_NOT_ARRAY->getMessage(),
        ];
        yield 'exclude traversal entry' => [
            ['copy' => ['src'], 'exclude' => ['../bad']],
            false,
            Message::MANIFEST_ENTRY_TRAVERSAL->getMessage('exclude', '../bad'),
        ];
        yield 'merge list' => [
            ['copy' => ['src'], 'merge' => ['ini']],
            false,
            Message::MANIFEST_MAP_NOT_OBJECT->getMessage('merge'),
        ];
        yield 'merge scalar' => [
            ['copy' => ['src'], 'merge' => 'ini'],
            false,
            Message::MANIFEST_MAP_NOT_OBJECT->getMessage('merge'),
        ];
        yield 'merge traversal pattern' => [
            ['copy' => ['src'], 'merge' => ['../.gitignore' => 'list']],
            false,
            Message::MANIFEST_ENTRY_TRAVERSAL->getMessage('merge', '../.gitignore'),
        ];
        yield 'merge unknown strategy' => [
            ['copy' => ['src'], 'merge' => ['.editorconfig' => 'yaml']],
            false,
            Message::MANIFEST_MAP_VALUE_INVALID->getMessage(
                'merge',
                '.editorconfig',
                'strategy',
                'yaml',
                'ini, list, text',
            ),
        ];
        yield 'merge value not string' => [
            ['copy' => ['src'], 'merge' => ['.gitignore' => ['list']]],
            false,
            Message::MANIFEST_MAP_VALUE_NOT_STRING->getMessage('merge', '.gitignore'),
        ];
        yield 'modes empty pattern' => [
            ['copy' => ['src'], 'modes' => ['' => 'preserve']],
            false,
            Message::MANIFEST_ENTRY_EMPTY->getMessage('modes'),
        ];
        yield 'modes scalar' => [
            ['copy' => ['src'], 'modes' => 'invalid'],
            false,
            Message::MANIFEST_MAP_NOT_OBJECT->getMessage('modes'),
        ];
        yield 'modes traversal pattern' => [
            ['copy' => ['src'], 'modes' => ['../escape' => 'preserve']],
            false,
            Message::MANIFEST_ENTRY_TRAVERSAL->getMessage('modes', '../escape'),
        ];
        yield 'modes unknown mode' => [
            ['copy' => ['src'], 'modes' => ['config/*.php' => 'unknown-mode']],
            false,
            Message::MANIFEST_MAP_VALUE_INVALID->getMessage(
                'modes',
                'config/*.php',
                'mode',
                'unknown-mode',
                'append, prepend, preserve, replace',
            ),
        ];
        yield 'modes value not string' => [
            ['copy' => ['src'], 'modes' => ['config/*.php' => 123]],
            false,
            Message::MANIFEST_MAP_VALUE_NOT_STRING->getMessage('modes', 'config/*.php'),
        ];
    }

    /**
     * @return iterable<string, array{array<mixed>, bool, array<string, mixed>}>
     */
    public static function validManifests(): iterable
    {
        yield 'backslash separators' => [
            ['copy' => ['config\\web.php', ['from' => 'stubs\\app\\.env', 'to' => 'app\\.env']]],
            false,
            self::validated(
                [
                    ['from' => 'config/web.php', 'to' => 'config/web.php'],
                    ['from' => 'stubs/app/.env', 'to' => 'app/.env'],
                ],
            ),
        ];
        yield 'colon past the drive-letter position' => [
            ['copy' => ['some/module:file.php']],
            false,
            self::validated([['from' => 'some/module:file.php', 'to' => 'some/module:file.php']]),
        ];
        yield 'consumer keys in inline block' => [
            ['copy' => ['src'], 'manifest' => 'x.json', 'allowed-packages' => [], 'auto' => false],
            true,
            self::validated([['from' => 'src', 'to' => 'src']]),
        ];
        yield 'empty merge object' => [
            ['copy' => ['src'], 'merge' => []],
            false,
            self::validated([['from' => 'src', 'to' => 'src']]),
        ];
        yield 'every exclude entry' => [
            ['copy' => ['src'], 'exclude' => ['first.php', 'second.php', 'third.php']],
            false,
            self::validated([['from' => 'src', 'to' => 'src']], ['first.php', 'second.php', 'third.php']),
        ];
        yield 'every mode' => [
            [
                'copy' => ['src'],
                'modes' => [
                    'replace.php' => 'replace',
                    'preserve.php' => 'preserve',
                    'append.txt' => 'append',
                    'prepend.txt' => 'prepend',
                ],
            ],
            false,
            self::validated(
                [['from' => 'src', 'to' => 'src']],
                modes: [
                    'replace.php' => FileMode::Replace,
                    'preserve.php' => FileMode::Preserve,
                    'append.txt' => FileMode::Append,
                    'prepend.txt' => FileMode::Prepend,
                ],
            ),
        ];
        yield 'from-to entries' => [
            [
                'copy' => [
                    ['from' => 'metadata/.editorconfig', 'to' => '.editorconfig'],
                    ['from' => 'metadata/.gitignore', 'to' => '.gitignore'],
                ],
            ],
            false,
            self::validated(
                [
                    ['from' => 'metadata/.editorconfig', 'to' => '.editorconfig'],
                    ['from' => 'metadata/.gitignore', 'to' => '.gitignore'],
                ],
            ),
        ];
        yield 'integer-like mode pattern' => [
            ['copy' => ['123'], 'modes' => ['123' => 'preserve']],
            false,
            self::validated([['from' => '123', 'to' => '123']], modes: [123 => FileMode::Preserve]),
        ];
        yield 'merge strategies' => [
            ['copy' => ['src'], 'merge' => ['.editorconfig' => 'ini', '**/.*ignore' => 'list', '123' => 'text']],
            false,
            self::validated(
                [['from' => 'src', 'to' => 'src']],
                merge: [
                    '.editorconfig' => MergeStrategyKind::Ini,
                    '**/.*ignore' => MergeStrategyKind::ListFile,
                    123 => MergeStrategyKind::Text,
                ],
            ),
        ];
        yield 'minimal manifest' => [
            ['copy' => ['src', 'config']],
            false,
            self::validated([['from' => 'src', 'to' => 'src'], ['from' => 'config', 'to' => 'config']]),
        ];
    }

    /**
     * Returns the validated structure of a manifest.
     *
     * @param list<array{from: string, to: string}> $copy Normalised `copy` entries.
     * @param list<string> $exclude `exclude` patterns.
     * @param array<array-key, FileMode> $modes Resolved `modes` map.
     * @param array<array-key, MergeStrategyKind> $merge Resolved `merge` map.
     *
     * @return array<string, mixed> Validated manifest.
     */
    private static function validated(array $copy, array $exclude = [], array $modes = [], array $merge = []): array
    {
        return ['copy' => $copy, 'exclude' => $exclude, 'modes' => $modes, 'merge' => $merge];
    }
}
