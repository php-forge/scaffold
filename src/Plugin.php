<?php

declare(strict_types=1);

namespace PHPForge\Scaffold;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Registers the {@see EventSubscriber} that scaffolds provider files after `composer install`, `composer update` and
 * `composer create-project`.
 */
final class Plugin implements PluginInterface
{
    /**
     * Subscriber registered with Composer's event dispatcher, or `null` while the plugin is inactive.
     */
    private EventSubscriber|null $subscriber = null;

    /**
     * Creates the event subscriber and registers it with Composer's event dispatcher.
     *
     * @param Composer $composer Composer instance whose event dispatcher receives the subscriber.
     * @param IOInterface $io Composer IO; unused.
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->subscriber = new EventSubscriber();

        $composer->getEventDispatcher()->addSubscriber($this->subscriber);
    }

    /**
     * Removes the registered event subscriber from Composer's event dispatcher.
     *
     * @param Composer $composer Composer instance whose event dispatcher holds the subscriber.
     * @param IOInterface $io Composer IO; unused.
     */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        if ($this->subscriber !== null) {
            $composer->getEventDispatcher()->removeListener($this->subscriber);
            $this->subscriber = null;
        }
    }

    /**
     * Performs no cleanup; scaffolded files and `scaffold-lock.json` stay in the project.
     *
     * @param Composer $composer Composer instance; unused.
     * @param IOInterface $io Composer IO; unused.
     */
    public function uninstall(Composer $composer, IOInterface $io): void {}
}
