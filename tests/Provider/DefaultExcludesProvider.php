<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\Manifest\DefaultExcludesTest;

/**
 * Data provider for {@see DefaultExcludesTest} test cases.
 */
final class DefaultExcludesProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function excludedPaths(): iterable
    {
        return self::rows(
            [
                'composer.json',
                'composer.lock',
                'vendor/autoload.php',
                'vendor/nested/pkg/file.php',
                '.git',
                '.git/HEAD',
                '.github/workflows/ci.yml',
                '.gitattributes',
                '.gitignore',
                'tests/unit/SomeTest.php',
                'tests/functional/deep/Fixture.php',
                'phpunit.xml',
                'phpunit.xml.dist',
                '.phpunit.cache/foo',
                'phpstan.neon',
                'phpstan.neon.dist',
                'phpstan.cache/bar',
                'infection.json5',
                'infection.log',
                '.editorconfig',
                '.php-cs-fixer.php',
                '.php-cs-fixer.dist.php',
                'ecs.php',
                'psalm.xml',
                'README.md',
                'CHANGELOG.md',
                'LICENSE',
                'docs/providers.md',
                'scaffold.json',
                'scaffold-lock.json',
                'runtime/cache/foo',
                'runtime/.gitignore',
                'node_modules/pkg/index.js',
                '.idea/workspace.xml',
                '.DS_Store',
            ],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function includedPaths(): iterable
    {
        return self::rows(
            [
                'src/controllers/SiteController.php',
                'src/models/User.php',
                'config/params.php',
                'config/web.php',
                'migrations/M2024_CreateUserTable.php',
                'public/index.php',
                'public/assets/.gitkeep',
                'yii',
                'rbac/items.php',
                'resources/mail/layouts/html.php',
                '.vscode/settings.json',
                '.gitlab-ci.yml',
                '.github-actions.yml',
                'sub/.DS_Store',
            ],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPrunableDirectories(): iterable
    {
        return self::rows(
            [
                'src',
                'config',
                'migrations',
                'public',
                'rbac',
                'resources',
                'vendor/bin',
                '.git/hooks',
                'tests/unit',
                'docs/nested',
                '',
            ],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prunableDirectories(): iterable
    {
        return self::rows(
            [
                'vendor',
                '.git',
                '.github',
                'tests',
                '.phpunit.cache',
                'phpunit.cache',
                'phpstan.cache',
                '.infection',
                'docs',
                'runtime',
                'node_modules',
                '.idea',
            ],
        );
    }

    /**
     * Returns one row per path, keyed by the path.
     *
     * @param list<string> $paths Relative paths.
     *
     * @return iterable<string, array{string}>
     */
    private static function rows(array $paths): iterable
    {
        foreach ($paths as $path) {
            yield $path === '' ? 'empty path' : $path => [$path];
        }
    }
}
