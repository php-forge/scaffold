<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Package\RootPackage;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\EventSubscriber;
use PHPForge\Scaffold\Tests\Provider\EventSubscriberProvider;
use ReflectionProperty;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\{AfterTest, BeforeTest};
use Testo\Test;

/**
 * Unit tests for {@see EventSubscriber} event registration, the `extra.scaffold.auto` guard, the vendor directory
 * guard, and the single run per Composer process.
 *
 * Every Composer instance has an empty `vendor-dir`, so a run that passes the `auto` guard stops at the vendor
 * directory guard with an error.
 *
 * {@see EventSubscriberProvider} for test case data providers.
 */
#[Test]
#[Covers(EventSubscriber::class)]
final class EventSubscriberTest
{
    /**
     * Ensures `auto: false` skips the run and writes only the notice.
     */
    public function autoFalseSkipsTheRunWithANotice(): void
    {
        $io = new BufferIO();

        (new EventSubscriber())->onPostInstall(
            self::event(ScriptEvents::POST_INSTALL_CMD, ['scaffold' => ['auto' => false]], $io),
        );

        Assert::same(
            $io->getOutput(),
            Message::SCAFFOLD_AUTO_DISABLED->getMessage() . PHP_EOL,
            'Only the auto-disabled notice may be written.',
        );
    }

    /**
     * Ensures `post-create-project-cmd` runs when no install or update ran before it.
     */
    public function postCreateProjectRunsWhenNoInstallOrUpdateRan(): void
    {
        $io = new BufferIO();

        (new EventSubscriber())->onPostCreateProject(self::event(ScriptEvents::POST_CREATE_PROJECT_CMD, [], $io));

        Assert::same(
            $io->getOutput(),
            Message::SCAFFOLD_VENDOR_DIR_UNRESOLVABLE->getMessage() . PHP_EOL,
            'The run must reach the vendor directory guard.',
        );
    }

    /**
     * Ensures `post-create-project-cmd` writes nothing after `post-install-cmd` ran.
     */
    public function postCreateProjectSkipsTheRunAfterPostInstall(): void
    {
        $subscriber = new EventSubscriber();
        $io = new BufferIO();

        $subscriber->onPostInstall(self::event(ScriptEvents::POST_INSTALL_CMD, [], new BufferIO()));
        $subscriber->onPostCreateProject(self::event(ScriptEvents::POST_CREATE_PROJECT_CMD, [], $io));

        Assert::same(
            $io->getOutput(),
            '',
            'The second run must write nothing.',
        );
    }

    /**
     * Ensures `post-create-project-cmd` writes nothing after `post-update-cmd` ran.
     */
    public function postCreateProjectSkipsTheRunAfterPostUpdate(): void
    {
        $subscriber = new EventSubscriber();
        $io = new BufferIO();

        $subscriber->onPostUpdate(self::event(ScriptEvents::POST_UPDATE_CMD, [], new BufferIO()));
        $subscriber->onPostCreateProject(self::event(ScriptEvents::POST_CREATE_PROJECT_CMD, [], $io));

        Assert::same(
            $io->getOutput(),
            '',
            'The second run must write nothing.',
        );
    }

    /**
     * Clears the process-wide run flag, so no test depends on the handlers that ran before it.
     */
    #[BeforeTest]
    #[AfterTest]
    public function resetScaffoldRanFlag(): void
    {
        (new ReflectionProperty(EventSubscriber::class, 'scaffoldRan'))->setValue(null, false);
    }

    /**
     * Ensures any `extra` block without `auto: false` passes the `auto` guard.
     *
     * @param array<string, mixed> $extra Root package `extra` block.
     */
    #[DataProvider(EventSubscriberProvider::class . '::autoEnabledExtra')]
    public function runPassesTheAutoGuardWhenAutoIsNotFalse(array $extra): void
    {
        $io = new BufferIO();

        (new EventSubscriber())->onPostInstall(self::event(ScriptEvents::POST_INSTALL_CMD, $extra, $io));

        Assert::same(
            $io->getOutput(),
            Message::SCAFFOLD_VENDOR_DIR_UNRESOLVABLE->getMessage() . PHP_EOL,
            'The run must reach the vendor directory guard.',
        );
    }

    /**
     * Ensures each subscribed script event maps to its handler.
     */
    public function subscribesTheThreeScriptEvents(): void
    {
        Assert::same(
            EventSubscriber::getSubscribedEvents(),
            [
                ScriptEvents::POST_CREATE_PROJECT_CMD => 'onPostCreateProject',
                ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
                ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate',
            ],
            'Each script event must map to its handler.',
        );
    }

    /**
     * Returns a script event for a Composer instance with an empty `vendor-dir` and a root package holding `$extra`.
     *
     * @param string $name Script event name.
     * @param array<string, mixed> $extra Root package `extra` block.
     * @param BufferIO $io IO bound to the event.
     */
    private static function event(string $name, array $extra, BufferIO $io): ScriptEvent
    {
        $config = new Config(false);

        $config->merge(['config' => ['vendor-dir' => '']]);

        $package = new RootPackage('fixture/root', '1.0.0.0', '1.0.0');

        $package->setExtra($extra);

        $composer = new Composer();

        $composer->setConfig($config);
        $composer->setPackage($package);

        return new ScriptEvent($name, $composer, $io, true);
    }
}
