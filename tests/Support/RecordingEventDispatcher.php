<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use Composer\Composer;
use Composer\EventDispatcher\{EventDispatcher, EventSubscriberInterface};
use Composer\IO\NullIO;

/**
 * Composer {@see EventDispatcher} that records every subscriber added and every listener removed, in call order, before
 * delegating to the real dispatcher.
 */
final class RecordingEventDispatcher extends EventDispatcher
{
    /**
     * Recorded calls in order, each the method name and its argument.
     *
     * @var list<array{string, mixed}>
     */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct(new Composer(), new NullIO());
    }

    /**
     * Records the subscriber, then registers its listeners.
     *
     * @param EventSubscriberInterface $subscriber Subscriber to register.
     */
    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->calls[] = ['addSubscriber', $subscriber];

        parent::addSubscriber($subscriber);
    }

    /**
     * Records the listener, then removes it.
     *
     * @param (callable(): mixed)|object $listener Callable or object whose listeners are removed.
     */
    public function removeListener($listener): void
    {
        $this->calls[] = ['removeListener', $listener];

        parent::removeListener($listener);
    }
}
