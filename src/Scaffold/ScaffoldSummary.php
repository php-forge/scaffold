<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold;

use PHPForge\Scaffold\Console\Message;

use function implode;

/**
 * Counts the outcomes of a scaffold run and formats its one-line summary.
 */
final class ScaffoldSummary
{
    /**
     * Number of `replace` destinations skipped as user-modified although their provider stub changed since the last
     * apply.
     */
    public int $conflict = 0;
    /**
     * Number of errors written to the error output: manifests that failed to load and mappings that failed to apply.
     */
    public int $errors = 0;
    /**
     * Number of lock entries that no allowed and installed provider ships anymore.
     */
    public int $orphan = 0;
    /**
     * Number of existing `preserve` destinations kept although their provider stub changed since the last apply.
     */
    public int $outdated = 0;
    /**
     * Number of mappings whose destination was left unchanged, ejected files included.
     */
    public int $unchanged = 0;
    /**
     * Number of warnings written to the error output.
     */
    public int $warnings = 0;
    /**
     * Number of destinations created or rewritten.
     */
    public int $written = 0;

    /**
     * Returns the summary line, such as `[scaffold] 3 files written, 8 unchanged, 1 conflict, 1 warning.`, listing the
     * `outdated`, `conflict`, `orphan`, warning and error counts only when they are not zero.
     *
     * @param string $command Command name inserted after the prefix, such as `sync` for `[scaffold] sync: ...`, or an
     * empty string for none.
     *
     * @return string Summary line that starts with `[scaffold]` and ends with a period.
     */
    public function format(string $command = ''): string
    {
        $parts = [
            Message::FRAGMENT_COUNT_WRITTEN->getMessage(
                $this->written,
                Message::plural($this->written, Message::FRAGMENT_FILE, Message::FRAGMENT_FILES),
            ),
            Message::FRAGMENT_COUNT_UNCHANGED->getMessage($this->unchanged),
        ];

        $states = [
            [$this->outdated, Message::FRAGMENT_OUTDATED],
            [$this->conflict, Message::FRAGMENT_CONFLICT],
            [$this->orphan, Message::FRAGMENT_ORPHAN],
        ];

        foreach ($states as [$count, $state]) {
            if ($count > 0) {
                $parts[] = Message::FRAGMENT_COUNT->getMessage($count, $state->getMessage());
            }
        }

        if ($this->warnings > 0) {
            $parts[] = Message::FRAGMENT_COUNT->getMessage(
                $this->warnings,
                Message::plural($this->warnings, Message::FRAGMENT_WARNING, Message::FRAGMENT_WARNINGS),
            );
        }

        if ($this->errors > 0) {
            $parts[] = Message::FRAGMENT_COUNT->getMessage(
                $this->errors,
                Message::plural($this->errors, Message::FRAGMENT_ERROR, Message::FRAGMENT_ERRORS),
            );
        }

        return Message::SCAFFOLD_SUMMARY->getMessage(
            $command === '' ? '' : Message::FRAGMENT_COMMAND->getMessage($command),
            implode(', ', $parts),
        );
    }

    /**
     * Returns the summary line of {@see self::format()}, or `null` when nothing was written and nothing needs
     * attention.
     *
     * @return string|null Summary line, or `null` when every counter except `unchanged` is zero.
     */
    public function line(): string|null
    {
        $quiet = $this->written === 0
            && $this->warnings === 0
            && $this->errors === 0
            && $this->outdated === 0
            && $this->conflict === 0
            && $this->orphan === 0;

        return $quiet ? null : $this->format();
    }
}
