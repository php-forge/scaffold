<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

use PHPForge\Scaffold\Console\Message;

use function array_sum;
use function implode;

/**
 * Counts the outcomes of a `reapply` run and formats its summary line.
 */
final class ReapplySummary
{
    /**
     * Number of entries skipped for an error.
     */
    public int $errors = 0;
    /**
     * @var array<string, int> Number of entries skipped without an error, keyed by reason (`ejected`, `orphaned`,
     * `preserve`, `user-modified`), in first-seen order.
     */
    public array $skipped = [];
    /**
     * Number of entries whose destination already matched.
     */
    public int $unchanged = 0;
    /**
     * Number of entries written, or that a dry run would write.
     */
    public int $written = 0;

    /**
     * Returns the summary line, such as `[scaffold] reapply: 3 written, 7 unchanged, 1 skipped (user-modified), 0
     * errors.`.
     *
     * @param bool $dryRun Whether the run only previewed the changes.
     *
     * @return string Summary line prefixed with `[scaffold]`.
     */
    public function line(bool $dryRun = false): string
    {
        $skipped = array_sum($this->skipped);

        $reasons = [];

        foreach ($this->skipped as $reason => $count) {
            $reasons[] = $count === $skipped ? $reason : Message::FRAGMENT_COUNT->getMessage($count, $reason);
        }

        return Message::REAPPLY_SUMMARY->getMessage(
            $dryRun ? Message::FRAGMENT_DRY_RUN->getMessage() : '',
            $this->written,
            $dryRun ? Message::FRAGMENT_WOULD_BE_WRITTEN->getMessage() : Message::FRAGMENT_WRITTEN->getMessage(),
            $this->unchanged,
            $skipped,
            $reasons === [] ? '' : Message::FRAGMENT_PARENTHESIZED->getMessage(implode(', ', $reasons)),
            $this->errors,
            Message::plural($this->errors, Message::FRAGMENT_ERROR, Message::FRAGMENT_ERRORS),
        );
    }

    /**
     * Counts an entry skipped without an error.
     *
     * @param string $reason Skip reason: `ejected`, `orphaned`, `preserve` or `user-modified`.
     */
    public function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }
}
