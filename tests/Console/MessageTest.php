<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Tests\Provider\ConsoleMessageProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see Message} template rendering against hand-written literals for every case.
 *
 * {@see ConsoleMessageProvider} for test case data providers.
 */
#[Test]
#[Covers(Message::class)]
final class MessageTest
{
    /**
     * Ensures each case renders its pinned literal; the provider yields one row per declared case.
     *
     * @param list<int|string> $arguments
     */
    #[DataProvider(ConsoleMessageProvider::class . '::cases')]
    public function rendersThePinnedLiteral(Message $case, array $arguments, string $expected): void
    {
        Assert::same(
            $case->getMessage(...$arguments),
            $expected,
            'Rendered message must match the pinned literal.',
        );
    }
}
