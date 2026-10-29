<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use Closure;
use PHPForge\Scaffold\Tests\Services\StatusServiceTest;
use PHPForge\Scaffold\Tests\Support\InspectionProject;

use function mkdir;

/**
 * Data provider for {@see StatusServiceTest} test cases.
 */
final class StatusServiceProvider
{
    /**
     * @return iterable<string, array{Closure(InspectionProject): void, array<string, string>, int}>
     */
    public static function states(): iterable
    {
        yield 'append with consumer additions' => [
            static function (InspectionProject $project): void {
                $project
                    ->provider('p/a', ['.gitignore' => "/vendor\n"], ['.gitignore' => 'append'])
                    ->file('.gitignore', "/vendor\n")
                    ->track('.gitignore', 'p/a', 'append')
                    ->file('.gitignore', "/vendor\n/my-build\n");
            },
            ['.gitignore' => 'synced'],
            0,
        ];
        yield 'append without the new provider entry' => [
            static function (InspectionProject $project): void {
                $project
                    ->provider('p/a', ['.gitignore' => "/vendor\n"], ['.gitignore' => 'append'])
                    ->file('.gitignore', "/vendor\n")
                    ->track('.gitignore', 'p/a', 'append')
                    ->stub('p/a', '.gitignore', "/vendor\n/.config/gh\n");
            },
            ['.gitignore' => 'outdated'],
            1,
        ];
        yield 'prepend with the block' => [
            static function (InspectionProject $project): void {
                $project
                    ->provider('p/a', ['h.php' => "<?php\n"], ['h.php' => 'prepend'])
                    ->file('h.php', "<?php\necho 1;\n")
                    ->track('h.php', 'p/a', 'prepend');
            },
            ['h.php' => 'synced'],
            0,
        ];
        yield 'prepend without the new block' => [
            static function (InspectionProject $project): void {
                $project
                    ->provider('p/a', ['h.php' => "<?php\n"], ['h.php' => 'prepend'])
                    ->file('h.php', "<?php\necho 1;\n")
                    ->track('h.php', 'p/a', 'prepend')
                    ->stub('p/a', 'h.php', "<?php\n// header\n");
            },
            ['h.php' => 'outdated'],
            1,
        ];
        yield 'preserve unchanged' => [
            static function (InspectionProject $project): void {
                self::preserve($project);
            },
            ['ecs.php' => 'synced'],
            0,
        ];
        yield 'preserve with a new stub' => [
            static function (InspectionProject $project): void {
                self::preserve($project)->stub('p/a', 'ecs.php', "v2\n");
            },
            ['ecs.php' => 'outdated'],
            1,
        ];
        yield 'preserve edited by the consumer' => [
            static function (InspectionProject $project): void {
                self::preserve($project)->stub('p/a', 'ecs.php', "v2\n")->file('ecs.php', "mine\n");
            },
            ['ecs.php' => 'modified'],
            1,
        ];
        yield 'preserve without stub hash differing from the stub' => [
            static function (InspectionProject $project): void {
                $project
                    ->provider('p/a', ['ecs.php' => "v1\n"], ['ecs.php' => 'preserve'])
                    ->file('ecs.php', "mine\n")
                    ->track('ecs.php', 'p/a', 'preserve', ['stub-hash' => null]);
            },
            ['ecs.php' => 'synced'],
            0,
        ];
        yield 'replace unchanged' => [
            static function (InspectionProject $project): void {
                self::replace($project);
            },
            ['a.txt' => 'synced'],
            0,
        ];
        yield 'replace edited by the consumer' => [
            static function (InspectionProject $project): void {
                self::replace($project)->file('a.txt', "mine\n");
            },
            ['a.txt' => 'modified'],
            1,
        ];
        yield 'replace edited by the consumer with a new stub' => [
            static function (InspectionProject $project): void {
                self::replace($project)->file('a.txt', "mine\n")->stub('p/a', 'a.txt', "v2\n");
            },
            ['a.txt' => 'conflict'],
            1,
        ];
        yield 'replace with a new stub' => [
            static function (InspectionProject $project): void {
                self::replace($project)->stub('p/a', 'a.txt', "v2\n");
            },
            ['a.txt' => 'outdated'],
            1,
        ];
        yield 'replace without stub hash differing from the stub' => [
            static function (InspectionProject $project): void {
                $project
                    ->provider('p/a', ['a.txt' => "v1\n"])
                    ->file('a.txt', "v1\n")
                    ->track('a.txt', 'p/a', extra: ['stub-hash' => null])
                    ->stub('p/a', 'a.txt', "v2\n");
            },
            ['a.txt' => 'outdated'],
            1,
        ];
        yield 'missing destination' => [
            static function (InspectionProject $project): void {
                self::replace($project)->delete('a.txt');
            },
            ['a.txt' => 'missing'],
            1,
        ];
        yield 'ejected entry without a file on disk' => [
            static function (InspectionProject $project): void {
                $project
                    ->provider('p/a', ['a.txt' => "a\n"])
                    ->track('a.txt', 'p/a', extra: ['ejected' => true]);
            },
            ['a.txt' => 'ejected'],
            0,
        ];
        yield 'destination that is a directory' => [
            static function (InspectionProject $project): void {
                $project
                    ->provider('p/a', ['a.txt' => "a\n"])
                    ->track('a.txt', 'p/a');

                mkdir("{$project->root}/a.txt");
            },
            ['a.txt' => 'error'],
            1,
        ];
        yield 'provider manifest that cannot be loaded' => [
            static function (InspectionProject $project): void {
                self::replace($project)->file('vendor/p/a/composer.json', '{"extra": {"scaffold": {"copy": "nope"}}}');
            },
            ['a.txt' => 'error'],
            1,
        ];
        yield 'unsafe destination before the orphan check' => [
            static function (InspectionProject $project): void {
                self::replace($project)->track('../escape.php', 'p/a');
            },
            ['../escape.php' => 'error', 'a.txt' => 'synced'],
            1,
        ];
        yield 'provider not allowed' => [
            static function (InspectionProject $project): void {
                self::replace($project)
                    ->provider('p/denied', ['d.txt' => "d\n"])
                    ->file('d.txt', "d\n")
                    ->track('d.txt', 'p/denied');
            },
            ['a.txt' => 'synced', 'd.txt' => 'orphan'],
            1,
        ];
        yield 'provider not installed' => [
            static function (InspectionProject $project): void {
                self::replace($project)->installed(['other/pkg' => '1.0.0']);
            },
            ['a.txt' => 'orphan'],
            1,
        ];
        yield 'provider no longer ships the file' => [
            static function (InspectionProject $project): void {
                $project
                    ->provider('p/a', ['a.txt' => "v1\n", 'old.txt' => "old\n"])
                    ->file('a.txt', "v1\n")
                    ->file('old.txt', "old\n")
                    ->track('a.txt', 'p/a')
                    ->track('old.txt', 'p/a')
                    ->provider('p/a', ['a.txt' => "v1\n"]);
            },
            ['a.txt' => 'synced', 'old.txt' => 'orphan'],
            1,
        ];
    }

    /**
     * Tracks `ecs.php` in `preserve` mode, identical to its stub.
     */
    private static function preserve(InspectionProject $project): InspectionProject
    {
        return $project
            ->provider('p/a', ['ecs.php' => "v1\n"], ['ecs.php' => 'preserve'])
            ->file('ecs.php', "v1\n")
            ->track('ecs.php', 'p/a', 'preserve');
    }

    /**
     * Tracks `a.txt` in `replace` mode, identical to its stub.
     */
    private static function replace(InspectionProject $project): InspectionProject
    {
        return $project
            ->provider('p/a', ['a.txt' => "v1\n"])
            ->file('a.txt', "v1\n")
            ->track('a.txt', 'p/a');
    }
}
