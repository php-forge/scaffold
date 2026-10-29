<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use PHPForge\Scaffold\Console\Message;

use function implode;

/**
 * Builds the expected scaffold summary lines from the {@see Message} templates.
 */
final class ExpectedSummary
{
    /**
     * Returns the scaffold summary line with the written and unchanged counts followed by `$parts`.
     *
     * @param int $written Number of written files.
     * @param int $unchanged Number of unchanged files.
     * @param string ...$parts Rendered attention parts, such as {@see self::part()} results.
     *
     * @return string Expected summary line.
     */
    public static function line(int $written, int $unchanged, string ...$parts): string
    {
        return self::withCommand('', $written, $unchanged, ...$parts);
    }

    /**
     * Returns the summary part that pairs `$count` with the label rendered by `$label`.
     *
     * @param int $count Number to render.
     * @param Message $label Fragment that labels the count.
     *
     * @return string Expected summary part.
     */
    public static function part(int $count, Message $label): string
    {
        return Message::FRAGMENT_COUNT->getMessage($count, $label->getMessage());
    }

    /**
     * Returns the `reapply` summary line for the given counts.
     *
     * @param int $written Number of written entries, or of entries that would be written with `$dryRun`.
     * @param int $unchanged Number of unchanged entries.
     * @param int $skipped Number of skipped entries.
     * @param int $errors Number of errors.
     * @param string ...$reasons Rendered skip reasons, such as `FRAGMENT_COUNT` results, in output order.
     *
     * @return string Expected summary line of a run that writes the files.
     */
    public static function reapply(int $written, int $unchanged, int $skipped, int $errors, string ...$reasons): string
    {
        return self::reapplyLine('', Message::FRAGMENT_WRITTEN, $written, $unchanged, $skipped, $errors, $reasons);
    }

    /**
     * Returns the `reapply --dry-run` summary line for the given counts.
     *
     * @param int $written Number of entries that would be written.
     * @param int $unchanged Number of unchanged entries.
     * @param int $skipped Number of skipped entries.
     * @param int $errors Number of errors.
     * @param string ...$reasons Rendered skip reasons, such as `FRAGMENT_COUNT` results, in output order.
     *
     * @return string Expected summary line of a run that only previews the files.
     */
    public static function reapplyDryRun(
        int $written,
        int $unchanged,
        int $skipped,
        int $errors,
        string ...$reasons,
    ): string {
        return self::reapplyLine(
            Message::FRAGMENT_DRY_RUN->getMessage(),
            Message::FRAGMENT_WOULD_BE_WRITTEN,
            $written,
            $unchanged,
            $skipped,
            $errors,
            $reasons,
        );
    }

    /**
     * Returns the scaffold summary line of `$command` with the written and unchanged counts followed by `$parts`.
     *
     * @param string $command Command name, or an empty string for none.
     * @param int $written Number of written files.
     * @param int $unchanged Number of unchanged files.
     * @param string ...$parts Rendered attention parts, such as {@see self::part()} results.
     *
     * @return string Expected summary line.
     */
    public static function withCommand(string $command, int $written, int $unchanged, string ...$parts): string
    {
        $all = [
            Message::FRAGMENT_COUNT_WRITTEN->getMessage(
                $written,
                $written === 1 ? Message::FRAGMENT_FILE->getMessage() : Message::FRAGMENT_FILES->getMessage(),
            ),
            Message::FRAGMENT_COUNT_UNCHANGED->getMessage($unchanged),
            ...$parts,
        ];

        return Message::SCAFFOLD_SUMMARY->getMessage(
            $command === '' ? '' : Message::FRAGMENT_COMMAND->getMessage($command),
            implode(', ', $all),
        );
    }

    /**
     * Returns the `reapply` summary line with the given prefix and written label.
     *
     * @param string $dryRun Rendered dry-run marker, or an empty string for none.
     * @param Message $writtenLabel Fragment that labels the written count.
     * @param int $written Number of written entries.
     * @param int $unchanged Number of unchanged entries.
     * @param int $skipped Number of skipped entries.
     * @param int $errors Number of errors.
     * @param array<array-key, string> $reasons Rendered skip reasons, in output order.
     *
     * @return string Expected summary line.
     */
    private static function reapplyLine(
        string $dryRun,
        Message $writtenLabel,
        int $written,
        int $unchanged,
        int $skipped,
        int $errors,
        array $reasons,
    ): string {
        return Message::REAPPLY_SUMMARY->getMessage(
            $dryRun,
            $written,
            $writtenLabel->getMessage(),
            $unchanged,
            $skipped,
            $reasons === [] ? '' : Message::FRAGMENT_PARENTHESIZED->getMessage(implode(', ', $reasons)),
            $errors,
            $errors === 1 ? Message::FRAGMENT_ERROR->getMessage() : Message::FRAGMENT_ERRORS->getMessage(),
        );
    }
}
