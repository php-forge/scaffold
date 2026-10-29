<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Merge;

use LogicException;
use PHPForge\Scaffold\Exception\Message;

use function count;
use function explode;
use function implode;
use function preg_last_error_msg;
use function preg_replace;
use function str_contains;
use function str_ends_with;
use function substr;

/**
 * Splits, inspects and rebuilds text line by line while keeping the original end-of-line bytes.
 */
final class Lines
{
    /**
     * Returns the dominant end-of-line sequence: `"\r\n"`, `"\r"`, or `"\n"` (also for content without one).
     *
     * @param string $content Content to inspect.
     *
     * @return string `"\r\n"` when present, otherwise `"\r"` when present, otherwise `"\n"`.
     */
    public static function detectEol(string $content): string
    {
        if (str_contains($content, "\r\n")) {
            return "\r\n";
        }

        if (str_contains($content, "\r")) {
            return "\r";
        }

        return "\n";
    }

    /**
     * Returns whether the content ends with an end-of-line sequence.
     *
     * @param string $content Content to inspect.
     *
     * @return bool `true` when the content ends with `"\n"` or `"\r"`.
     */
    public static function endsWithEol(string $content): bool
    {
        return str_ends_with($content, "\n") || str_ends_with($content, "\r");
    }

    /**
     * Converts `"\r\n"` and `"\r"` terminators to `"\n"`.
     *
     * @param string $content Content to normalise.
     *
     * @return string Content with `"\n"` terminators only.
     */
    public static function normalise(string $content): string
    {
        return preg_replace('/\r\n|\r/', "\n", $content)
            ?? throw new LogicException(Message::REGEX_FAILED->getMessage(preg_last_error_msg()));
    }

    /**
     * Rebuilds consumer segments verbatim with inserted lines and appended blocks, each block after one blank line.
     *
     * @param list<array{string, string}> $segments Consumer `[text, terminator]` segments.
     * @param array<int, list<string>> $insertAfter Lines to insert after the segment with the given index; index `-1`
     * inserts before the first segment.
     * @param list<list<string>> $blocks Blocks of lines appended at the end, in order.
     * @param string $eol End-of-line sequence used for inserted lines.
     * @param bool $finalEol Whether the result must end with an end-of-line sequence.
     *
     * @return string Rebuilt content.
     */
    public static function rebuild(
        array $segments,
        array $insertAfter,
        array $blocks,
        string $eol,
        bool $finalEol,
    ): string {
        $output = isset($insertAfter[-1]) ? implode($eol, $insertAfter[-1]) . $eol : '';

        foreach ($segments as $index => [$text, $terminator]) {
            $output .= "{$text}{$terminator}";

            if (isset($insertAfter[$index])) {
                $output = self::terminate($output, $eol) . implode($eol, $insertAfter[$index]) . $eol;
            }
        }

        foreach ($blocks as $block) {
            $output = self::terminate($output, $eol);

            if ($output !== '' && !self::endsWithBlankLine($output)) {
                $output .= $eol;
            }

            $output .= implode($eol, $block);
        }

        if ($finalEol) {
            return self::terminate($output, $eol);
        }

        if (str_ends_with($output, "\r\n")) {
            return substr($output, 0, -2);
        }

        return self::endsWithEol($output) ? substr($output, 0, -1) : $output;
    }

    /**
     * Splits content normalised to `"\n"` into lines, ignoring the terminator of the last line.
     *
     * @param string $content Content normalised to `"\n"` terminators.
     *
     * @return list<string> Lines; empty for empty content.
     */
    public static function split(string $content): array
    {
        if ($content === '') {
            return [];
        }

        if (str_ends_with($content, "\n")) {
            $content = substr($content, 0, -1);
        }

        return explode("\n", $content);
    }

    /**
     * Splits raw content into lines that keep their original terminator.
     *
     * @param string $content Content with any mix of `"\r\n"`, `"\r"` and `"\n"` terminators.
     *
     * @return list<array{string, string}> `[text, terminator]` pairs; the last terminator is empty when the content
     * does not end with an end-of-line sequence.
     */
    public static function splitRaw(string $content): array
    {
        $segments = [];

        $chunks = explode("\n", $content);
        $lastChunk = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            $chunkEol = $index < $lastChunk ? "\n" : '';

            if ($chunkEol === "\n" && str_ends_with($chunk, "\r")) {
                $chunk = substr($chunk, 0, -1);
                $chunkEol = "\r\n";
            }

            // Legacy Mac CR terminators inside the chunk produce their own segments; the final piece takes the chunk
            // terminator, except for the empty piece a trailing terminator leaves behind.
            $pieces = explode("\r", $chunk);
            $lastPiece = count($pieces) - 1;

            foreach ($pieces as $pieceIndex => $piece) {
                if ($pieceIndex < $lastPiece) {
                    $segments[] = [$piece, "\r"];
                } elseif ($piece !== '' || $chunkEol !== '') {
                    $segments[] = [$piece, $chunkEol];
                }
            }
        }

        return $segments;
    }

    /**
     * Returns whether the last line of terminated content is blank.
     *
     * @param string $output Content that ends with an end-of-line sequence.
     *
     * @return bool `true` when the content is a single terminator or ends with two consecutive terminators.
     */
    private static function endsWithBlankLine(string $output): bool
    {
        $normalised = self::normalise($output);

        return $normalised === "\n" || str_ends_with($normalised, "\n\n");
    }

    /**
     * Terminates non-empty content that does not end with an end-of-line sequence.
     *
     * @param string $output Content to terminate.
     * @param string $eol End-of-line sequence to append.
     *
     * @return string Content ending with an end-of-line sequence, or the empty string unchanged.
     */
    private static function terminate(string $output, string $eol): string
    {
        return $output !== '' && !self::endsWithEol($output) ? "{$output}{$eol}" : $output;
    }
}
