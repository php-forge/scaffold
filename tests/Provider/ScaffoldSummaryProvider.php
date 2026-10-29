<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Scaffold\ScaffoldSummary;
use PHPForge\Scaffold\Services\ReapplySummary;
use PHPForge\Scaffold\Tests\Scaffold\ScaffoldSummaryTest;
use PHPForge\Scaffold\Tests\Support\ExpectedSummary;

use function implode;

/**
 * Data provider for {@see ScaffoldSummaryTest} test cases.
 */
final class ScaffoldSummaryProvider
{
    /**
     * @return iterable<string, array{ReapplySummary, bool, string}>
     */
    public static function reapplyLines(): iterable
    {
        $single = new ReapplySummary();

        $single->written = 3;
        $single->unchanged = 7;
        $single->errors = 1;
        $single->skip('user-modified');

        yield 'single skip reason without its count' => [
            $single,
            false,
            Message::REAPPLY_SUMMARY->getMessage(
                '',
                3,
                Message::FRAGMENT_WRITTEN->getMessage(),
                7,
                1,
                Message::FRAGMENT_PARENTHESIZED->getMessage('user-modified'),
                1,
                Message::FRAGMENT_ERROR->getMessage(),
            ),
        ];

        $several = new ReapplySummary();

        $several->written = 3;
        $several->unchanged = 7;
        $several->errors = 2;

        $several->skip('user-modified');
        $several->skip('preserve');

        yield 'several skip reasons with their counts in a dry run' => [
            $several,
            true,
            Message::REAPPLY_SUMMARY->getMessage(
                Message::FRAGMENT_DRY_RUN->getMessage(),
                3,
                Message::FRAGMENT_WOULD_BE_WRITTEN->getMessage(),
                7,
                2,
                Message::FRAGMENT_PARENTHESIZED->getMessage(
                    implode(
                        ', ',
                        [
                            Message::FRAGMENT_COUNT->getMessage(1, 'user-modified'),
                            Message::FRAGMENT_COUNT->getMessage(1, 'preserve'),
                        ],
                    ),
                ),
                2,
                Message::FRAGMENT_ERRORS->getMessage(),
            ),
        ];

        yield 'nothing skipped' => [
            new ReapplySummary(),
            false,
            Message::REAPPLY_SUMMARY->getMessage(
                '',
                0,
                Message::FRAGMENT_WRITTEN->getMessage(),
                0,
                0,
                '',
                0,
                Message::FRAGMENT_ERRORS->getMessage(),
            ),
        ];
    }

    /**
     * @return iterable<string, array{ScaffoldSummary, string|null}>
     */
    public static function scaffoldLines(): iterable
    {
        yield 'nothing happened' => [
            self::summary(),
            null,
        ];
        yield 'only unchanged files' => [
            self::summary(unchanged: 8),
            null,
        ];
        yield 'one written file' => [
            self::summary(written: 1, unchanged: 8),
            ExpectedSummary::line(1, 8),
        ];
        yield 'written files and warnings' => [
            self::summary(written: 3, unchanged: 8, warnings: 2),
            ExpectedSummary::line(3, 8, ExpectedSummary::part(2, Message::FRAGMENT_WARNINGS)),
        ];
        yield 'one warning only' => [
            self::summary(unchanged: 8, warnings: 1),
            ExpectedSummary::line(0, 8, ExpectedSummary::part(1, Message::FRAGMENT_WARNING)),
        ];
        yield 'outdated only' => [
            self::summary(outdated: 1),
            ExpectedSummary::line(0, 0, ExpectedSummary::part(1, Message::FRAGMENT_OUTDATED)),
        ];
        yield 'conflicts only' => [
            self::summary(conflict: 2),
            ExpectedSummary::line(0, 0, ExpectedSummary::part(2, Message::FRAGMENT_CONFLICT)),
        ];
        yield 'orphan only' => [
            self::summary(orphan: 1),
            ExpectedSummary::line(0, 0, ExpectedSummary::part(1, Message::FRAGMENT_ORPHAN)),
        ];
        yield 'one error only' => [
            self::summary(errors: 1),
            ExpectedSummary::line(0, 0, ExpectedSummary::part(1, Message::FRAGMENT_ERROR)),
        ];
        yield 'every count, in order' => [
            self::summary(written: 1, unchanged: 4, outdated: 1, conflict: 2, orphan: 3, warnings: 1, errors: 2),
            ExpectedSummary::line(
                1,
                4,
                ExpectedSummary::part(1, Message::FRAGMENT_OUTDATED),
                ExpectedSummary::part(2, Message::FRAGMENT_CONFLICT),
                ExpectedSummary::part(3, Message::FRAGMENT_ORPHAN),
                ExpectedSummary::part(1, Message::FRAGMENT_WARNING),
                ExpectedSummary::part(2, Message::FRAGMENT_ERRORS),
            ),
        ];
    }

    /**
     * Returns a scaffold summary with the given counters.
     *
     * @param int $written Number of written files.
     * @param int $unchanged Number of unchanged files.
     * @param int $outdated Number of outdated files.
     * @param int $conflict Number of conflicts.
     * @param int $orphan Number of orphans.
     * @param int $warnings Number of warnings.
     * @param int $errors Number of errors.
     *
     * @return ScaffoldSummary Summary with the counters set.
     */
    private static function summary(
        int $written = 0,
        int $unchanged = 0,
        int $outdated = 0,
        int $conflict = 0,
        int $orphan = 0,
        int $warnings = 0,
        int $errors = 0,
    ): ScaffoldSummary {
        $summary = new ScaffoldSummary();

        $summary->written = $written;
        $summary->unchanged = $unchanged;
        $summary->outdated = $outdated;
        $summary->conflict = $conflict;
        $summary->orphan = $orphan;
        $summary->warnings = $warnings;
        $summary->errors = $errors;

        return $summary;
    }
}
