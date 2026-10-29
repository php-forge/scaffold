<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Merge;

use function in_array;
use function str_ends_with;
use function strrpos;
use function substr;

/**
 * Resolves the merge strategy of a destination from its kind, or from its basename with `text` as the fallback.
 */
final class StrategyResolver
{
    /**
     * Basenames merged with the `list` strategy by default.
     */
    private const array LIST_BASENAMES = [
        '.codespellignore',
        '.dockerignore',
        '.eslintignore',
        '.gitattributes',
        '.gitignore',
        '.markdownlintignore',
        '.npmignore',
        '.prettierignore',
        '.stylelintignore',
    ];

    /**
     * Returns the default strategy for a destination basename.
     *
     * @param string $destination Destination path relative to the project root.
     *
     * @return MergeStrategy Strategy of the basename default kind.
     */
    public static function for(string $destination): MergeStrategy
    {
        return self::forKind(self::defaultKind($destination));
    }

    /**
     * Returns the strategy implementing a kind.
     *
     * @param MergeStrategyKind $kind Strategy kind.
     *
     * @return MergeStrategy New strategy instance for the kind.
     */
    public static function forKind(MergeStrategyKind $kind): MergeStrategy
    {
        return match ($kind) {
            MergeStrategyKind::Ini => new IniMergeStrategy(),
            MergeStrategyKind::ListFile => new ListMergeStrategy(),
            MergeStrategyKind::Text => new TextMergeStrategy(),
        };
    }

    /**
     * Returns the default strategy kind for a destination basename.
     *
     * @param string $destination Destination path relative to the project root.
     *
     * @return MergeStrategyKind `Ini` for `.editorconfig` and `*.ini`, `ListFile` for known ignore lists, else `Text`.
     */
    private static function defaultKind(string $destination): MergeStrategyKind
    {
        $slash = strrpos($destination, '/');

        $basename = $slash === false ? $destination : substr($destination, $slash + 1);

        if ($basename === '.editorconfig' || str_ends_with($basename, '.ini')) {
            return MergeStrategyKind::Ini;
        }

        if (in_array($basename, self::LIST_BASENAMES, true)) {
            return MergeStrategyKind::ListFile;
        }

        return MergeStrategyKind::Text;
    }
}
