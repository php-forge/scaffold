<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Merge;

use function array_key_first;
use function array_pop;
use function array_slice;
use function count;
use function preg_match;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Merges EditorConfig and INI files section by section; existing keys always keep the consumer value.
 *
 * @see https://spec.editorconfig.org/
 *
 * @phpstan-type Section array{
 *   id: string,
 *   header: string,
 *   lead: list<string>,
 *   body: list<string>,
 *   keys: array<array-key, string>,
 *   lastKey: int,
 * }
 * @phpstan-type Document array{
 *   preambleKeys: array<array-key, string>,
 *   preambleLastKey: int|null,
 *   preambleLength: int,
 *   sections: list<Section>,
 * }
 * @phpstan-import-type MergePlan from AbstractLineMergeStrategy
 */
final class IniMergeStrategy extends AbstractLineMergeStrategy
{
    /**
     * Plans missing preamble keys, missing keys inside existing sections and missing sections appended whole.
     *
     * @param list<string> $provider Provider lines.
     * @param list<string> $consumer Consumer lines without terminators.
     *
     * @return MergePlan Lines to insert after a consumer line index and blocks to append; both empty when satisfied.
     */
    protected function plan(array $provider, array $consumer): array
    {
        $source = self::parse($provider);
        $target = self::parse($consumer);

        $insertAfter = [];
        $blocks = [];

        $missingPreamble = [];

        foreach ($source['preambleKeys'] as $key => $line) {
            if (!isset($target['preambleKeys'][$key])) {
                $missingPreamble[] = $line;
            }
        }

        if ($missingPreamble !== []) {
            if ($target['preambleLastKey'] !== null) {
                $insertAfter[$target['preambleLastKey']] = $missingPreamble;
            } else {
                if ($target['preambleLength'] === 0 && $consumer !== []) {
                    // Keep one blank line between the inserted keys and the consumer's first section header.
                    $missingPreamble[] = '';
                }

                $insertAfter[-1] = $missingPreamble;
            }
        }

        // First occurrence of each consumer section, and the keys of every occurrence of that section.
        $firstSection = [];
        $sectionKeys = [];

        foreach ($target['sections'] as $section) {
            $firstSection[$section['id']] ??= $section;
            // Union keeps integer keys, which PHP makes of numeric INI keys and array unpacking would renumber.
            $sectionKeys[$section['id']] = ($sectionKeys[$section['id']] ?? []) + $section['keys'];
        }

        // Blocks appended for sections the consumer lacks, keyed by section identity.
        $appended = [];

        foreach ($source['sections'] as $section) {
            $id = $section['id'];

            if (isset($firstSection[$id])) {
                foreach ($section['keys'] as $key => $line) {
                    if (!isset($sectionKeys[$id][$key])) {
                        $sectionKeys[$id][$key] = $line;
                        $insertAfter[$firstSection[$id]['lastKey']][] = $line;
                    }
                }

                continue;
            }

            if (isset($appended[$id])) {
                // A provider section declared twice: its later keys extend the block already appended.
                foreach ($section['keys'] as $key => $line) {
                    if (!isset($sectionKeys[$id][$key])) {
                        $sectionKeys[$id][$key] = $line;
                        $blocks[$appended[$id]][] = $line;
                    }
                }

                continue;
            }

            $appended[$id] = count($blocks);
            $sectionKeys[$id] = $section['keys'];
            $blocks[] = [...$section['lead'], $section['header'], ...$section['body']];
        }

        return [
            'insertAfter' => $insertAfter,
            'blocks' => $blocks,
        ];
    }

    /**
     * Returns whether the trimmed line is a comment (`#` or `;`).
     *
     * @param string $trimmed Trimmed line.
     *
     * @return bool `true` when the line starts with `#` or `;`.
     */
    private static function isComment(string $trimmed): bool
    {
        return str_starts_with($trimmed, '#') || str_starts_with($trimmed, ';');
    }

    /**
     * Returns the lower-cased key of a trimmed `key = value` line, or `null` for any other line.
     *
     * @param string $trimmed Trimmed line.
     *
     * @return string|null Lower-cased, trimmed key, or `null` for blank, comment, header, keyless or `=`-less lines.
     */
    private static function keyOf(string $trimmed): string|null
    {
        if ($trimmed === '' || self::isComment($trimmed) || self::sectionId($trimmed) !== null) {
            return null;
        }

        $position = strpos($trimmed, '=');

        if ($position === false) {
            return null;
        }

        $key = strtolower(trim(substr($trimmed, 0, $position)));

        return $key === '' ? null : $key;
    }

    /**
     * Parses lines into a preamble and sections; comments directly above a header belong to that header.
     *
     * @param list<string> $lines Lines to parse.
     *
     * @return Document Parsed document.
     */
    private static function parse(array $lines): array
    {
        $headers = [];

        foreach ($lines as $index => $line) {
            $id = self::sectionId(trim($line));

            if ($id !== null) {
                $headers[$index] = ['id' => $id, 'line' => $line];
            }
        }

        $count = count($lines);

        $preambleLength = array_key_first($headers) ?? $count;
        $preambleKeys = [];
        $preambleLastKey = null;

        foreach (array_slice($lines, 0, $preambleLength) as $index => $line) {
            $trimmed = trim($line);

            $key = self::keyOf($trimmed);

            if ($key !== null) {
                $preambleKeys[$key] ??= $trimmed;
                $preambleLastKey = $index;
            }
        }

        // Start of each header's lead (comment lines directly above it). The climb stops at the previous header, which
        // is never a comment, or before the first line, which reads as an empty line.
        $spans = [];

        foreach ($headers as $headerIndex => $header) {
            $start = $headerIndex;

            while (self::isComment(trim($lines[$start - 1] ?? ''))) {
                $start--;
            }

            $spans[] = ['index' => $headerIndex, 'header' => $header, 'leadStart' => $start];
        }

        $sections = [];

        foreach ($spans as $position => ['index' => $headerIndex, 'header' => $header, 'leadStart' => $leadStart]) {
            $bodyEnd = $spans[$position + 1]['leadStart'] ?? $count;
            $body = array_slice($lines, $headerIndex + 1, $bodyEnd - $headerIndex - 1);

            while ($body !== [] && trim($body[count($body) - 1]) === '') {
                array_pop($body);
            }

            $keys = [];
            $lastKey = $headerIndex;

            foreach ($body as $offset => $line) {
                $key = self::keyOf(trim($line));

                if ($key !== null) {
                    $keys[$key] ??= $line;
                    $lastKey = $headerIndex + 1 + $offset;
                }
            }

            $sections[] = [
                'id' => $header['id'],
                'header' => $header['line'],
                'lead' => array_slice($lines, $leadStart, $headerIndex - $leadStart),
                'body' => $body,
                'keys' => $keys,
                'lastKey' => $lastKey,
            ];
        }

        return [
            'preambleKeys' => $preambleKeys,
            'preambleLastKey' => $preambleLastKey,
            'preambleLength' => $preambleLength,
            'sections' => $sections,
        ];
    }

    /**
     * Returns the identity of a trimmed `[section]` header, which is the header without a trailing `#` or `;` comment.
     *
     * @param string $trimmed Trimmed line.
     *
     * @return string|null Header up to its closing `]`, or `null` when the line is no section header.
     */
    private static function sectionId(string $trimmed): string|null
    {
        return preg_match('/^(\[.*\])\s*(?:[#;].*)?$/', $trimmed, $matches) === 1 ? $matches[1] : null;
    }
}
