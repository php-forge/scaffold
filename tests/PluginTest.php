<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests;

use Composer\Composer;
use Composer\IO\NullIO;
use PHPForge\Scaffold\{EventSubscriber, Plugin};
use PHPForge\Scaffold\Tests\Support\RecordingEventDispatcher;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function array_column;

/**
 * Unit tests for {@see Plugin} registering and removing its {@see EventSubscriber} on Composer's event dispatcher.
 */
#[Test]
#[Covers(Plugin::class)]
final class PluginTest
{
    /**
     * Ensures activation registers one event subscriber.
     */
    public function activateRegistersAnEventSubscriber(): void
    {
        $dispatcher = new RecordingEventDispatcher();

        (new Plugin())->activate(self::composer($dispatcher), new NullIO());

        Assert::same(
            array_column($dispatcher->calls, 0),
            ['addSubscriber'],
            "Only 'addSubscriber' must be called.",
        );
        Assert::instanceOf(
            $dispatcher->calls[0][1] ?? null,
            EventSubscriber::class,
            'The registered subscriber must be an event subscriber.',
        );
    }

    /**
     * Ensures deactivation removes the registered subscriber once, however often it runs.
     */
    public function deactivateRemovesTheRegisteredSubscriberOnlyOnce(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $composer = self::composer($dispatcher);
        $plugin = new Plugin();

        $plugin->activate($composer, new NullIO());
        $plugin->deactivate($composer, new NullIO());
        $plugin->deactivate($composer, new NullIO());

        Assert::same(
            array_column($dispatcher->calls, 0),
            ['addSubscriber', 'removeListener'],
            "A second deactivation must not call 'removeListener' again.",
        );
        Assert::same(
            $dispatcher->calls[1][1] ?? null,
            $dispatcher->calls[0][1] ?? null,
            'The removed listener must be the registered subscriber.',
        );
    }

    /**
     * Ensures deactivation without activation calls no dispatcher method.
     */
    public function deactivateWithoutActivationLeavesTheDispatcherUntouched(): void
    {
        $dispatcher = new RecordingEventDispatcher();

        (new Plugin())->deactivate(self::composer($dispatcher), new NullIO());

        Assert::same(
            $dispatcher->calls,
            [],
            'No dispatcher method may be called.',
        );
    }

    /**
     * Ensures uninstall leaves the subscriber registered.
     */
    public function uninstallKeepsTheSubscriberRegistered(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $composer = self::composer($dispatcher);
        $plugin = new Plugin();

        $plugin->activate($composer, new NullIO());
        $plugin->uninstall($composer, new NullIO());

        Assert::same(
            array_column($dispatcher->calls, 0),
            ['addSubscriber'],
            "Uninstall must not call 'removeListener'.",
        );
    }

    /**
     * Returns a Composer instance that uses `$dispatcher`.
     */
    private static function composer(RecordingEventDispatcher $dispatcher): Composer
    {
        $composer = new Composer();

        $composer->setEventDispatcher($dispatcher);

        return $composer;
    }
}
