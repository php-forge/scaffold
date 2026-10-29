<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Manifest;

use BackedEnum;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Merge\MergeStrategyKind;
use PHPForge\Scaffold\Security\PathValidator;
use RuntimeException;

use function array_column;
use function array_is_list;
use function array_key_exists;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sort;
use function str_replace;

/**
 * Validates a provider manifest and normalises it into typed `copy`, `exclude`, `modes` and `merge` entries.
 *
 * @phpstan-type CopyEntry array{from: string, to: string}
 * @phpstan-type ValidatedManifest array{
 *   copy: list<CopyEntry>,
 *   exclude: list<string>,
 *   modes: array<array-key, FileMode>,
 *   merge: array<array-key, MergeStrategyKind>,
 * }
 */
final class ManifestSchema
{
    /**
     * Consumer-side keys that may share the inline `extra.scaffold` block with the manifest body.
     */
    private const array CONSUMER_KEYS = ['allowed-packages', 'auto', 'manifest'];
    /**
     * Keys of a manifest body.
     */
    private const array MANIFEST_KEYS = ['copy', 'exclude', 'merge', 'modes'];

    /**
     * Validates a raw decoded manifest array and returns the typed structure.
     *
     * @param array<mixed> $raw Decoded JSON content of the manifest (or the inline `extra.scaffold` array).
     * @param bool $inline `true` when `$raw` is the inline `extra.scaffold` block, which may also carry the
     * consumer-side keys `manifest`, `allowed-packages` and `auto`.
     *
     * @throws RuntimeException when the manifest structure is invalid or contains an unknown key.
     *
     * @return array{
     *   copy: list<array{from: string, to: string}>,
     *   exclude: list<string>,
     *   modes: array<array-key, FileMode>,
     *   merge: array<array-key, MergeStrategyKind>,
     * }
     * Typed manifest with `copy[]` entries normalised to `{from, to}` form, `modes` values resolved to
     * {@see FileMode} cases and `merge` values resolved to {@see MergeStrategyKind} cases.
     */
    public function validate(array $raw, bool $inline = false): array
    {
        $this->validateKeys($raw, $inline);

        return [
            'copy' => $this->validateCopy($raw),
            'exclude' => $this->validateExclude($raw),
            'modes' => $this->validateEnumMap($raw, 'modes', FileMode::class, 'mode', false),
            'merge' => $this->validateEnumMap($raw, 'merge', MergeStrategyKind::class, 'strategy', true),
        ];
    }

    /**
     * Asserts that `$path` is a non-empty relative string without traversal segments or absolute prefixes.
     *
     * @param mixed $path Candidate path (validated to be string).
     * @param string $key Name of the manifest key being validated (for error messages).
     *
     * @throws RuntimeException when the path fails any of the safety checks.
     *
     * @phpstan-assert non-empty-string $path
     */
    private function assertSafeRelativePath(mixed $path, string $key): void
    {
        if (!is_string($path) || $path === '') {
            throw new RuntimeException(
                Message::MANIFEST_ENTRY_EMPTY->getMessage($key),
            );
        }

        if (PathValidator::isAbsolute($path)) {
            throw new RuntimeException(
                Message::MANIFEST_ENTRY_ABSOLUTE->getMessage($key, $path),
            );
        }

        if (PathValidator::hasTraversal($path)) {
            throw new RuntimeException(
                Message::MANIFEST_ENTRY_TRAVERSAL->getMessage($key, $path),
            );
        }
    }

    /**
     * Converts the backslash separators of a path to forward slashes.
     *
     * @param string $path Path to convert.
     *
     * @return string Path with forward slashes only.
     */
    private static function toForwardSlashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Validates the required `copy` list, normalising string entries to `{from, to}` pairs with the same path and
     * backslash separators to forward slashes.
     *
     * @param array<mixed> $raw Raw manifest array.
     *
     * @throws RuntimeException when `copy` is missing, empty, not a list, or holds an invalid entry.
     *
     * @return list<array{from: string, to: string}> List of normalised entries; both fields are non-empty, relative,
     * non-traversal paths with forward slashes.
     */
    private function validateCopy(array $raw): array
    {
        if (!array_key_exists('copy', $raw) || !is_array($raw['copy'])) {
            throw new RuntimeException(
                Message::MANIFEST_COPY_MISSING->getMessage(),
            );
        }

        if ($raw['copy'] !== [] && !array_is_list($raw['copy'])) {
            throw new RuntimeException(
                Message::MANIFEST_COPY_NOT_LIST->getMessage(),
            );
        }

        if ($raw['copy'] === []) {
            throw new RuntimeException(
                Message::MANIFEST_COPY_EMPTY->getMessage(),
            );
        }

        $entries = [];

        foreach ($raw['copy'] as $entry) {
            if (is_string($entry)) {
                $this->assertSafeRelativePath($entry, 'copy');

                $path = self::toForwardSlashes($entry);

                $entries[] = ['from' => $path, 'to' => $path];

                continue;
            }

            if (is_array($entry) && isset($entry['from'], $entry['to'])) {
                $this->assertSafeRelativePath($entry['from'], 'copy.from');
                $this->assertSafeRelativePath($entry['to'], 'copy.to');

                $entries[] = [
                    'from' => self::toForwardSlashes($entry['from']),
                    'to' => self::toForwardSlashes($entry['to']),
                ];

                continue;
            }

            throw new RuntimeException(Message::MANIFEST_COPY_ENTRY_INVALID->getMessage());
        }

        return $entries;
    }

    /**
     * Validates the optional `modes` or `merge` key: an object that maps safe patterns to values of `$enum`.
     *
     * @template TEnum of BackedEnum
     *
     * @param array<mixed> $raw Raw manifest array.
     * @param string $key Manifest key to validate (`modes` or `merge`).
     * @param class-string<TEnum> $enum Enum whose backing values the map may use.
     * @param string $label Name of one value in the error messages (`mode` or `strategy`).
     * @param bool $rejectList Whether a non-empty list is rejected as not an object.
     *
     * @throws RuntimeException when the key is not an object or holds an unsafe pattern or unknown value.
     *
     * @return array<array-key, TEnum> Values keyed by pattern; integer-like patterns become integer keys.
     */
    private function validateEnumMap(array $raw, string $key, string $enum, string $label, bool $rejectList): array
    {
        if (!array_key_exists($key, $raw)) {
            return [];
        }

        $map = $raw[$key];

        if (!is_array($map) || ($rejectList && $map !== [] && array_is_list($map))) {
            throw new RuntimeException(
                Message::MANIFEST_MAP_NOT_OBJECT->getMessage($key),
            );
        }

        $resolved = [];

        foreach ($map as $pattern => $value) {
            // PHP turns integer-like patterns ("123") into integer array keys.
            $pattern = (string) $pattern;

            $this->assertSafeRelativePath($pattern, $key);

            if (!is_string($value)) {
                throw new RuntimeException(
                    Message::MANIFEST_MAP_VALUE_NOT_STRING->getMessage($key, $pattern),
                );
            }

            $resolved[$pattern] = $enum::tryFrom($value) ?? throw new RuntimeException(
                Message::MANIFEST_MAP_VALUE_INVALID->getMessage(
                    $key,
                    $pattern,
                    $label,
                    $value,
                    implode(', ', array_column($enum::cases(), 'value')),
                ),
            );
        }

        return $resolved;
    }

    /**
     * Validates the optional `exclude` key.
     *
     * @param array<mixed> $raw Raw manifest array.
     *
     * @throws RuntimeException when `exclude` is not an array or holds an unsafe pattern.
     *
     * @return list<string> List of non-empty, relative, non-traversal patterns.
     */
    private function validateExclude(array $raw): array
    {
        if (!array_key_exists('exclude', $raw)) {
            return [];
        }

        if (!is_array($raw['exclude'])) {
            throw new RuntimeException(
                Message::MANIFEST_EXCLUDE_NOT_ARRAY->getMessage(),
            );
        }

        $patterns = [];

        foreach ($raw['exclude'] as $pattern) {
            $this->assertSafeRelativePath($pattern, 'exclude');

            $patterns[] = $pattern;
        }

        return $patterns;
    }

    /**
     * Rejects top-level keys outside the allowed set.
     *
     * @param array<mixed> $raw Raw manifest array.
     * @param bool $inline Whether the consumer-side keys are allowed too.
     *
     * @throws RuntimeException when a key is not allowed.
     */
    private function validateKeys(array $raw, bool $inline): void
    {
        $allowed = $inline ? [...self::MANIFEST_KEYS, ...self::CONSUMER_KEYS] : self::MANIFEST_KEYS;

        sort($allowed);

        foreach ($raw as $key => $_) {
            $key = (string) $key;

            if (!in_array($key, $allowed, true)) {
                throw new RuntimeException(
                    Message::MANIFEST_KEY_UNKNOWN->getMessage($key, implode(', ', $allowed)),
                );
            }
        }
    }
}
