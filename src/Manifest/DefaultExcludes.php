<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Manifest;

/**
 * Lists the provider meta-files that {@see ManifestExpander} skips when it walks a `copy[]` directory; explicit file
 * entries in `copy[]` bypass these patterns.
 */
final class DefaultExcludes
{
    /**
     * @var list<string> Glob patterns excluded by default from directory walks.
     */
    public const array PATTERNS = [
        // Composer metadata.
        'composer.json',
        'composer.lock',
        'vendor/**',
        // Version control ('.git' is a file in worktrees and submodules).
        '.git',
        '.git/**',
        '.github/**',
        '.gitattributes',
        '.gitignore',
        // Test framework.
        'tests/**',
        'phpunit.xml',
        'phpunit.xml.dist',
        '.phpunit.cache/**',
        'phpunit.cache/**',
        // Static analysis and style.
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpstan.cache/**',
        '.editorconfig',
        '.php-cs-fixer.php',
        '.php-cs-fixer.dist.php',
        'ecs.php',
        'psalm.xml',
        'psalm.xml.dist',
        // Mutation testing.
        'infection.json5',
        'infection.json',
        'infection.log',
        '.infection/**',
        // Documentation and meta.
        'README.md',
        'CHANGELOG.md',
        'LICENSE',
        'docs/**',
        // Scaffold metadata itself.
        'scaffold.json',
        'scaffold-lock.json',
        // Runtime bucket (providers ship explicit '.gitignore' if they want).
        'runtime/**',
        // Package managers and editor or OS artefacts.
        'node_modules/**',
        '.idea/**',
        '.DS_Store',
    ];
}
