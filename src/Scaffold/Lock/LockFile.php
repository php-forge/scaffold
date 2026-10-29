<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Lock;

use JsonException;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use RuntimeException;

use function array_diff_key;
use function array_flip;
use function array_key_exists;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function preg_match;
use function rtrim;

/**
 * Reads and writes `scaffold-lock.json`, which records the provider and hashes of every scaffolded file.
 *
 * @phpstan-type LockEntry array{
 *   hash: string,
 *   provider: string,
 *   source: string,
 *   mode: string,
 *   stub-hash?: string,
 *   merge?: string,
 *   ejected?: bool,
 *   adopted?: bool,
 * }
 */
final class LockFile
{
    /**
     * Entry keys that this class manages; {@see self::write()} carries other entry keys over from disk.
     */
    private const array ENTRY_KEYS = [
        'adopted',
        'ejected',
        'hash',
        'merge',
        'mode',
        'provider',
        'source',
        'stub-hash',
    ];

    /**
     * @var array{
     *   providers: array<string, mixed>,
     *   files: array<array-key, LockEntry>,
     * }|null Cached lock data to avoid redundant file reads. Set to `null` when the cache is invalidated.
     */
    private array|null $cache = null;
    /**
     * @var array<string, array<array-key, mixed>> Entry keys outside {@see self::ENTRY_KEYS} found in the lock on disk,
     * keyed by destination.
     */
    private array $unknownEntryKeys = [];
    /**
     * @var array<string, mixed>|null Top-level keys other than `providers` and `files` found in the lock on disk, or
     * `null` when the lock has not been read yet.
     */
    private array|null $unknownKeys = null;

    /**
     * @param string $projectRoot Absolute path to the project root that holds `scaffold-lock.json`.
     */
    public function __construct(private readonly string $projectRoot) {}

    /**
     * Returns `true` when `scaffold-lock.json` exists on disk.
     *
     * @return bool `true` when the lock file exists, `false` otherwise.
     */
    public function exists(): bool
    {
        return is_file($this->getPath());
    }

    /**
     * Returns the absolute path to `scaffold-lock.json`.
     *
     * @return string Absolute path to the lock file under the project root.
     */
    public function getPath(): string
    {
        return rtrim($this->projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'scaffold-lock.json';
    }

    /**
     * Reads and returns the lock data, or a default empty structure when the file does not exist.
     *
     * @throws RuntimeException when the file exists but cannot be read or decoded as a JSON object; the message names
     * the file and the JSON error, and reports Git conflict markers when present.
     *
     * @return array{
     *   providers: array<string, mixed>,
     *   files: array<array-key, LockEntry>,
     * } Lock data; integer-like destinations (`"123"`) come back as integer `files` keys.
     */
    public function read(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->getPath();

        if (!is_file($path)) {
            $this->unknownKeys = [];
            $this->unknownEntryKeys = [];
            $this->cache = ['providers' => [], 'files' => []];

            return $this->cache;
        }

        $content = Filesystem::read($path, Message::LOCK_UNREADABLE->getMessage($path));

        try {
            $decoded = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                self::decodeErrorMessage($path, $content, $e),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(
                Message::LOCK_NOT_OBJECT->getMessage($path),
            );
        }

        $providers = is_array($decoded['providers'] ?? null) ? $decoded['providers'] : [];
        $files = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];

        $unknownKeys = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key) && $key !== 'providers' && $key !== 'files') {
                $unknownKeys[$key] = $value;
            }
        }

        $this->unknownKeys = $unknownKeys;
        $this->unknownEntryKeys = [];

        foreach ($files as $destination => $entry) {
            if (is_array($entry)) {
                $extra = array_diff_key($entry, array_flip(self::ENTRY_KEYS));

                if ($extra !== []) {
                    $this->unknownEntryKeys[(string) $destination] = $extra;
                }
            }
        }

        /**
         * @var array{
         *   providers: array<string, mixed>,
         *   files: array<array-key, LockEntry>,
         * } $normalized */
        $normalized = ['providers' => $providers, 'files' => $files];

        $this->cache = $normalized;

        return $this->cache;
    }

    /**
     * Writes lock data atomically with `providers` and `files` as objects sorted by key, carrying over unknown
     * top-level keys and the unknown keys of entries still present in `$data`.
     *
     * @param array<string, mixed> $data Lock data to persist.
     *
     * @throws RuntimeException when the data cannot be encoded, the existing lock cannot be read, or the file cannot
     * be written.
     */
    public function write(array $data): void
    {
        if ($this->unknownKeys === null) {
            // Load the keys to preserve from the lock on disk before replacing it.
            $this->read();
        }

        $this->cache = null;

        $path = $this->getPath();

        $files = $data['files'] ?? [];

        if (is_array($files)) {
            foreach ($this->unknownEntryKeys as $destination => $extra) {
                if (is_array($files[$destination] ?? null)) {
                    $files[$destination] += $extra;
                }
            }
        }

        $payload = [
            'providers' => self::sortedMap($data['providers'] ?? []),
            'files' => self::sortedMap($files),
        ];

        foreach ($data as $key => $value) {
            if ($key !== 'providers' && $key !== 'files') {
                $payload[$key] = $value;
            }
        }

        foreach ($this->unknownKeys ?? [] as $key => $value) {
            if (!array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $e) {
            throw new RuntimeException(
                Message::LOCK_ENCODE_FAILED->getMessage($path, $e->getMessage()),
                0,
                $e,
            );
        }

        Filesystem::writeAtomic($path, $json, Message::LOCK_UNWRITABLE->getMessage($path));
    }

    /**
     * Builds the error message for a lock file that is not valid JSON, pointing out Git conflict markers when present.
     *
     * @param string $path Absolute path to the lock file.
     * @param string $content Raw content of the lock file.
     * @param JsonException $error Decoding error raised for the content.
     *
     * @return string Error message that names the file and the JSON error.
     */
    private static function decodeErrorMessage(string $path, string $content, JsonException $error): string
    {
        if (preg_match('/^(?:<{7}|={7}|>{7})/m', $content) === 1) {
            return Message::LOCK_CONFLICT_MARKERS->getMessage($path, $error->getMessage());
        }

        return Message::LOCK_INVALID_JSON->getMessage($path, $error->getMessage());
    }

    /**
     * Returns `$map` sorted by key in byte order as an object, so empty maps encode as `{}` and integer-like keys never
     * turn the map into a JSON list.
     *
     * @param mixed $map Map to normalise; values that are not arrays are returned unchanged.
     *
     * @return mixed Key-sorted object for an array, or `$map` unchanged otherwise.
     */
    private static function sortedMap(mixed $map): mixed
    {
        if (!is_array($map)) {
            return $map;
        }

        ksort($map, SORT_STRING);

        return (object) $map;
    }
}
