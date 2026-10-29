<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\EventSubscriberTest;

/**
 * Data provider for {@see EventSubscriberTest} test cases.
 */
final class EventSubscriberProvider
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function autoEnabledExtra(): iterable
    {
        yield 'no scaffold block' => [[]];
        yield 'non-array scaffold block' => [['scaffold' => 'not-an-array']];
        yield 'explicit auto true' => [['scaffold' => ['auto' => true]]];
    }
}
