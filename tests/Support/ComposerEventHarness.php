<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Package\CompletePackage;
use Composer\Script\{Event as ScriptEvent, ScriptEvents};
use PHPForge\Scaffold\EventSubscriber;
use ReflectionProperty;
use Testo\Lifecycle\{AfterTest, BeforeTest};

/**
 * Dispatches Composer script events to {@see EventSubscriber} for a fake project built by {@see FakeProjectBuilder},
 * with a real {@see Composer} instance whose local repository holds the provider `demo/scaffold`.
 *
 * This runs the code path of the plugin in production without shelling out to `composer install`. The class that uses
 * this trait must also use {@see TempDirectoryTrait}, which holds the fake project.
 */
trait ComposerEventHarness
{
    /**
     * Clears the process-wide run flag of {@see EventSubscriber} before and after each test, so
     * `post-create-project-cmd` runs unless the test itself dispatched `post-install-cmd` or `post-update-cmd` first.
     */
    #[BeforeTest]
    #[AfterTest]
    public function resetScaffoldRanFlag(): void
    {
        (new ReflectionProperty(EventSubscriber::class, 'scaffoldRan'))->setValue(null, false);
    }

    /**
     * Dispatches `$event` to a new {@see EventSubscriber} for a fresh Composer instance of the project of `$builder`
     * that has `demo/scaffold` installed with `$manifest`, and returns the Composer output.
     *
     * @param FakeProjectBuilder $builder Fake project, with its `composer.json` written by {@see self::project()}.
     * @param array<string, mixed> $manifest Raw `extra.scaffold` of `demo/scaffold`.
     * @param string $event Script event name, such as {@see ScriptEvents::POST_INSTALL_CMD}.
     * @param string $version Pretty version of `demo/scaffold`.
     * @param BufferIO $io IO of the run, which keeps the output when the run throws.
     *
     * @return string Output written to `$io`.
     */
    protected function dispatch(
        FakeProjectBuilder $builder,
        array $manifest,
        string $event = ScriptEvents::POST_INSTALL_CMD,
        string $version = '1.0.0',
        BufferIO $io = new BufferIO(),
    ): string {
        $composer = Factory::create(
            $io,
            "{$builder->getProjectRoot()}/composer.json",
            disablePlugins: true,
            disableScripts: true,
        );

        $package = new CompletePackage('demo/scaffold', "{$version}.0", $version);

        $package->setExtra(['scaffold' => $manifest]);
        $composer->getRepositoryManager()->getLocalRepository()->addPackage($package);

        $subscriber = new EventSubscriber();
        $scriptEvent = new ScriptEvent($event, $composer, $io, true);

        if ($event === ScriptEvents::POST_CREATE_PROJECT_CMD) {
            $subscriber->onPostCreateProject($scriptEvent);
        } elseif ($event === ScriptEvents::POST_UPDATE_CMD) {
            $subscriber->onPostUpdate($scriptEvent);
        } else {
            $subscriber->onPostInstall($scriptEvent);
        }

        return $io->getOutput();
    }

    /**
     * Creates the fake project in the temporary directory, with a `composer.json` that pins the root version, points
     * `vendor-dir` at the fake vendor directory and holds `$scaffold` as `extra.scaffold`.
     *
     * @param array<array-key, mixed> $scaffold Root `extra.scaffold`.
     */
    protected function project(array $scaffold = ['allowed-packages' => ['demo/scaffold']]): FakeProjectBuilder
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createComposerJson(
            [
                'name' => 'demo/project',
                'version' => '1.0.0',
                'config' => ['vendor-dir' => $builder->getVendorDir()],
                'extra' => ['scaffold' => $scaffold],
            ],
        );

        return $builder;
    }
}
