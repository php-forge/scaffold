<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Functional;

use Composer\Script\ScriptEvents;
use PHPForge\Scaffold\EventSubscriber;
use PHPForge\Scaffold\Scaffold\Lock\{Hasher, LockFile};
use PHPForge\Scaffold\Services\EjectService;
use PHPForge\Scaffold\Tests\Support\{
    BufferedOutputWriter,
    ComposerEventHarness,
    FileAssert,
    TempDirectoryTrait,
};
use Testo\Assert;
use Testo\Test;

use function array_column;
use function file_get_contents;

/**
 * Functional tests for the apply lifecycle across Composer events, durable eject and provider version bumps,
 * dispatched through {@see EventSubscriber}.
 */
#[Test]
final class ApplyLifecycleTest
{
    use ComposerEventHarness;
    use TempDirectoryTrait;

    /**
     * Ensures an ejected file keeps the user content and its ejected entry, silently, on the next install.
     */
    public function ejectThenInstallKeepsUserFile(): void
    {
        $builder = $this->project();

        $manifest = ['copy' => ['.styleci.yml']];

        $builder->createStubFile('demo/scaffold', '.styleci.yml', "preset: psr12\n");

        $this->dispatch($builder, $manifest);

        $builder->createProjectFile('.styleci.yml', "preset: laravel\n");

        (new EjectService())
            ->run($builder->getProjectRoot(), new BufferedOutputWriter(), '.styleci.yml', true);

        Assert::same(
            $this->dispatch($builder, $manifest),
            '',
            'An ejected file needs no output.',
        );
        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/.styleci.yml",
            "preset: laravel\n",
            'User edit must survive the next install.',
        );
        Assert::true(
            (new LockFile($builder->getProjectRoot()))->read()['files']['.styleci.yml']['ejected'] ?? false,
            "Entry must stay 'ejected'.",
        );
    }

    /**
     * Ensures a provider bump through `post-update-cmd` applies each mode and records the new version and stub hashes.
     */
    public function providerBumpUpdatesAppendAndReplaceLeavesPreserveAndRecordsStubHash(): void
    {
        $builder = $this->project();

        $manifest = [
            'copy' => ['.gitignore', '.styleci.yml', 'ecs.php'],
            'modes' => ['.gitignore' => 'append', 'ecs.php' => 'preserve'],
        ];

        $root = $builder->getProjectRoot();

        $stubs = "{$builder->getVendorDir()}/demo/scaffold";

        $builder->createStubFile('demo/scaffold', '.gitignore', "# composer\ncomposer.lock\n");
        $builder->createStubFile('demo/scaffold', '.styleci.yml', "preset: psr12\n");
        $builder->createStubFile('demo/scaffold', 'ecs.php', "<?php\n// v1\n");

        $this->dispatch($builder, $manifest);

        $builder->createProjectFile('.gitignore', "# composer\ncomposer.lock\n/build\n");
        $builder->createStubFile('demo/scaffold', '.gitignore', "# composer\ncomposer.lock\ncomposer.phar\n");
        $builder->createStubFile('demo/scaffold', '.styleci.yml', "preset: per\n");
        $builder->createStubFile('demo/scaffold', 'ecs.php', "<?php\n// v2\n");

        $this->dispatch($builder, $manifest, ScriptEvents::POST_UPDATE_CMD, '2.0.0');

        $lock = (new LockFile($root))->read();

        Assert::same(
            [
                '.gitignore' => file_get_contents("{$root}/.gitignore"),
                '.styleci.yml' => file_get_contents("{$root}/.styleci.yml"),
                'ecs.php' => file_get_contents("{$root}/ecs.php"),
            ],
            [
                '.gitignore' => "# composer\ncomposer.lock\n/build\ncomposer.phar\n",
                '.styleci.yml' => "preset: per\n",
                'ecs.php' => "<?php\n// v1\n",
            ],
            'Append must close the matching block, replace must update and preserve must keep the file.',
        );
        Assert::same(
            $lock['providers']['demo/scaffold'] ?? null,
            ['version' => '2.0.0', 'path' => $stubs],
            "Provider version must advance to '2.0.0'.",
        );
        Assert::same(
            array_column($lock['files'], 'stub-hash'),
            [
                Hasher::hash("{$stubs}/.gitignore"),
                Hasher::hash("{$stubs}/.styleci.yml"),
                Hasher::hash("{$stubs}/ecs.php"),
            ],
            'Stub hash of every file must match the bumped stub.',
        );
    }
}
