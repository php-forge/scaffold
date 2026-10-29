<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Merge\{IniMergeStrategy, ListMergeStrategy, MergeStrategy, MergeStrategyKind, TextMergeStrategy};
use PHPForge\Scaffold\Tests\Merge\StrategyResolverTest;

/**
 * Data provider for {@see StrategyResolverTest} test cases.
 */
final class StrategyResolverProvider
{
    /**
     * @return iterable<string, array{string, class-string<MergeStrategy>}>
     */
    public static function destinations(): iterable
    {
        yield 'codespell ignore list' => ['.codespellignore', ListMergeStrategy::class];
        yield 'Docker ignore list' => ['.dockerignore', ListMergeStrategy::class];
        yield 'dotenv template' => ['.env.dist', TextMergeStrategy::class];
        yield 'EditorConfig backup' => ['.editorconfig.bak', TextMergeStrategy::class];
        yield 'EditorConfig' => ['.editorconfig', IniMergeStrategy::class];
        yield 'ESLint ignore list' => ['.eslintignore', ListMergeStrategy::class];
        yield 'Git ignore list' => ['.gitignore', ListMergeStrategy::class];
        yield 'ignore list name without leading dot' => ['gitignore', TextMergeStrategy::class];
        yield 'markdownlint ignore list' => ['.markdownlintignore', ListMergeStrategy::class];
        yield 'nested Git attributes list' => ['sub/dir/.gitattributes', ListMergeStrategy::class];
        yield 'nested INI file' => ['config/php.ini', IniMergeStrategy::class];
        yield 'npm ignore list' => ['.npmignore', ListMergeStrategy::class];
        yield 'Prettier ignore list' => ['.prettierignore', ListMergeStrategy::class];
        yield 'Stylelint ignore list' => ['.stylelintignore', ListMergeStrategy::class];
    }

    /**
     * @return iterable<string, array{MergeStrategyKind, class-string<MergeStrategy>}>
     */
    public static function kinds(): iterable
    {
        yield 'ini' => [MergeStrategyKind::Ini, IniMergeStrategy::class];
        yield 'list' => [MergeStrategyKind::ListFile, ListMergeStrategy::class];
        yield 'text' => [MergeStrategyKind::Text, TextMergeStrategy::class];
    }
}
