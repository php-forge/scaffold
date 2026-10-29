<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Exception;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Tests\Provider\MessageProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function array_map;
use function array_values;
use function sort;

/**
 * Unit tests for {@see Message} template rendering against hand-written literals for every case.
 *
 * {@see MessageProvider} for test case data providers.
 */
#[Test]
#[Covers(Message::class)]
final class MessageTest
{
    /**
     * Ensures every message case has exactly one provider row.
     */
    public function everyCaseIsPinned(): void
    {
        $pinned = array_map(
            static fn(array $row): string => $row[0]->name,
            array_values(MessageProvider::cases()),
        );
        $declared = array_map(
            static fn(Message $message): string => $message->name,
            Message::cases(),
        );

        sort($pinned);
        sort($declared);

        Assert::same(
            $pinned,
            $declared,
            'Every case needs exactly one provider row.',
        );
    }

    /**
     * Ensures each case renders its pinned literal.
     *
     * @param list<int|string> $arguments
     */
    #[DataProvider(MessageProvider::class . '::cases')]
    public function rendersThePinnedLiteral(Message $case, array $arguments, string $expected): void
    {
        Assert::same(
            $case->getMessage(...$arguments),
            $expected,
            'Rendered message must match the pinned literal.',
        );
    }
}
