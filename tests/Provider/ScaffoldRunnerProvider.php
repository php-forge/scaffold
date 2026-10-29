<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\Scaffold\ScaffoldRunnerTest;

/**
 * Data provider for {@see ScaffoldRunnerTest} test cases.
 */
final class ScaffoldRunnerProvider
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function providerPaths(): iterable
    {
        yield 'install path inside the project root' => [
            '{tmp}/project/vendor/pkg',
            '{tmp}/project',
            'vendor/pkg',
        ];
        yield 'install path equal to the project root' => [
            '{tmp}/project',
            '{tmp}/project',
            '',
        ];
        yield 'install path outside the project root' => [
            '{tmp}/outside/pkg',
            '{tmp}/project',
            '{real}/outside/pkg',
        ];
        yield 'project root with trailing separator' => [
            '{tmp}/project/vendor/pkg',
            '{tmp}/project/',
            'vendor/pkg',
        ];
        yield 'filesystem root as project root' => [
            '{tmp}/project/pkg',
            '{root}',
            '{rel}/project/pkg',
        ];
        yield 'backslash in the install path' => [
            '{tmp}/project/subdir\\pkg',
            '{tmp}/project/subdir',
            'pkg',
        ];
        yield 'backslash in the project root' => [
            '{tmp}/project/subdir/pkg',
            '{tmp}/project\\subdir',
            'pkg',
        ];
        yield 'project root differing in case' => [
            '{tmp}/project/pkg',
            '{tmp}/Project',
            DIRECTORY_SEPARATOR === '\\' ? 'pkg' : '{real}/project/pkg',
        ];
    }
}
