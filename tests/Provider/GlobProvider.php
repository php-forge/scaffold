<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Merge\MergeStrategyKind;
use PHPForge\Scaffold\Tests\Manifest\GlobTest;

/**
 * Data provider for {@see GlobTest} test cases.
 */
final class GlobProvider
{
    /**
     * @return iterable<string, array{array<array-key, MergeStrategyKind>, string, MergeStrategyKind|null}>
     */
    public static function lookups(): iterable
    {
        yield 'exact match wins over an earlier glob' => [
            ['conf/*' => MergeStrategyKind::Text, 'conf/app.cfg' => MergeStrategyKind::Ini],
            'conf/app.cfg',
            MergeStrategyKind::Ini,
        ];
        yield 'first matching glob wins' => [
            ['conf/*' => MergeStrategyKind::Text, '**/*.ini' => MergeStrategyKind::Ini],
            'conf/app.ini',
            MergeStrategyKind::Text,
        ];
        yield 'integer-like exact key' => [
            [123 => MergeStrategyKind::Ini],
            '123',
            MergeStrategyKind::Ini,
        ];
        yield 'integer-like path against a glob' => [
            ['12*' => MergeStrategyKind::ListFile],
            '123',
            MergeStrategyKind::ListFile,
        ];
        yield 'no match' => [
            ['*.ini' => MergeStrategyKind::Ini],
            '.gitignore',
            null,
        ];
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function matches(): iterable
    {
        yield 'brace alternatives accept wildcards' => ['{src,lib}/**/*.{php,phtml}', 'lib/a/b.phtml', true];
        yield 'brace alternatives reject other prefixes' => ['{src,lib}/**/*.{php,phtml}', 'docs/a.php', false];
        yield 'brace empty alternative' => ['phpunit.xml{,.dist}', 'phpunit.xml', true];
        yield 'brace first alternative' => ['*.{php,js}', 'a.php', true];
        yield 'brace group is not literal' => ['*.{php,js}', 'a.{php,js}', false];
        yield 'brace second alternative' => ['*.{php,js}', 'a.js', true];
        yield 'brace suffix alternative' => ['phpunit.xml{,.dist}', 'phpunit.xml.dist', true];
        yield 'brace unlisted alternative' => ['*.{php,js}', 'a.css', false];
        yield 'brace without comma does not expand' => ['{a}', 'a', false];
        yield 'brace without comma is literal' => ['{a}', '{a}', true];
        yield 'double star crosses separators' => ['public/**', 'public/assets/css/app.css', true];
        yield 'double star slash matches a deep path' => ['**/foo', 'a/b/c/foo', true];
        yield 'double star slash matches a root file' => ['**/.gitignore', '.gitignore', true];
        yield 'exact path matches itself' => ['config/params.php', 'config/params.php', true];
        yield 'exact path rejects another file' => ['config/params.php', 'config/web.php', false];
        yield 'lone closing brace is literal' => ['a}', 'a}', true];
        yield 'nested braces do not expand recursively' => ['{a,{b,c}}', 'a', false];
        yield 'nested braces keep the outer braces literal' => ['{a,{b,c}}', '{a,b}', true];
        yield 'question mark does not match a separator' => ['yii?', 'yii/0', false];
        yield 'question mark matches one character' => ['yii?', 'yii0', true];
        yield 'single star does not cross a separator' => ['config/*.php', 'config/nested/params.php', false];
        yield 'single star matches the same level' => ['config/*.php', 'config/params.php', true];
        yield 'unclosed brace is literal' => ['{a', '{a', true];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function regexes(): iterable
    {
        yield 'anchors the full path' => ['config/*.php', '#^config/[^/]*\.php$#'];
        yield 'brace alternatives converted inside one group' => ['{a.b,*}', '#^(?:a\.b|[^/]*)$#'];
        yield 'double star slash is an optional directory chain' => ['**/foo', '#^(?:.*/)?foo$#'];
        yield 'escapes punctuation' => ['file.with.dots', '#^file\.with\.dots$#'];
    }
}
