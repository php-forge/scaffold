<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use PHPForge\Scaffold\Merge\MergeStrategy;
use Testo\Assert;

use function count;
use function explode;
use function preg_replace;
use function trim;

/**
 * Provides the golden-fixture assertions shared by the merge strategy tests.
 */
trait MergeAssertionsTrait
{
    /**
     * Asserts the full merge contract for one golden fixture.
     *
     * Checks the merged bytes, the `changed` flag, that every consumer line is kept in its original relative order, and
     * that a second merge is a byte-identical no-op.
     *
     * @param MergeStrategy $strategy Strategy under test.
     * @param string $provider Provider content.
     * @param string $consumer Consumer content.
     * @param string $expected Expected merged content.
     */
    private static function assertGoldenMerge(
        MergeStrategy $strategy,
        string $provider,
        string $consumer,
        string $expected,
    ): void {
        $result = $strategy->merge($provider, $consumer);
        $second = $strategy->merge($provider, $result->content);
        $consumerLines = self::linesOf($consumer);

        self::assertMergeSame(
            $result->content,
            $expected,
            'Merged bytes must match the golden fixture.',
        );
        self::assertMergeSame(
            $result->changed,
            $expected !== $consumer,
            'Changed flag must reflect a content change.',
        );
        self::assertMergeSame(
            self::linesKeptInOrder($consumerLines, self::linesOf($result->content)),
            $consumerLines,
            'Every consumer line must be kept in its original order.',
        );
        self::assertMergeSame(
            $second->changed,
            false,
            "Second run must report 'changed' as 'false'.",
        );
        self::assertMergeSame(
            $second->content,
            $result->content,
            'Second run must return identical bytes.',
        );
    }

    /**
     * Asserts that `$actual` is identical to `$expected`.
     *
     * @param mixed $actual Actual value.
     * @param mixed $expected Expected value.
     * @param string $message Failure message.
     */
    private static function assertMergeSame(mixed $actual, mixed $expected, string $message): void
    {
        Assert::same($actual, $expected, $message);
    }

    /**
     * Returns the lines of `$consumer` that appear in `$merged` in the same relative order, scanning `$merged` once.
     *
     * The result equals `$consumer` exactly when every consumer line is kept in order.
     *
     * @param list<string> $consumer Consumer lines.
     * @param list<string> $merged Merged lines.
     *
     * @return list<string> Consumer lines found in order.
     */
    private static function linesKeptInOrder(array $consumer, array $merged): array
    {
        $kept = [];
        $position = 0;
        $total = count($merged);

        foreach ($consumer as $line) {
            while ($position < $total && ($merged[$position] ?? null) !== $line) {
                $position++;
            }

            if ($position < $total) {
                $kept[] = $line;
                $position++;
            }
        }

        return $kept;
    }

    /**
     * Returns the lines of `$content` with terminators normalised.
     *
     * @param string $content Content to split.
     *
     * @return list<string> Lines.
     */
    private static function linesOf(string $content): array
    {
        $normalised = trim((string) preg_replace('/\r\n|\r/', "\n", $content), "\n");

        return $normalised === '' ? [] : explode("\n", $normalised);
    }
}
