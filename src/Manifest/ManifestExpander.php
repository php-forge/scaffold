<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Manifest;

use FilesystemIterator;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Merge\MergeStrategyKind;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function array_values;
use function is_dir;
use function is_file;
use function rtrim;
use function sort;
use function strlen;
use function substr;

/**
 * Expands a validated manifest into file mappings; a later `copy[]` entry overrides an earlier one per destination.
 *
 * @phpstan-type ValidatedManifest array{
 *   copy: list<array{from: string, to: string}>,
 *   exclude: list<string>,
 *   modes: array<array-key, FileMode>,
 *   merge?: array<array-key, MergeStrategyKind>,
 * }
 */
final class ManifestExpander
{
    /**
     * Expands a validated manifest into the list of file mappings that the scaffolder must apply.
     *
     * @param ValidatedManifest $manifest Validated manifest as returned by {@see ManifestSchema::validate()}.
     * @param string $providerPath Absolute path to the provider root on disk.
     * @param string $providerName Composer package name (used to attribute the mapping for provenance).
     *
     * @throws RuntimeException when a path listed in `copy[]` does not exist on disk.
     *
     * @return list<FileMapping> Concrete list of mappings, ready for the scaffolder.
     */
    public function expand(array $manifest, string $providerPath, string $providerName): array
    {
        $mappings = [];

        foreach ($manifest['copy'] as $entry) {
            $from = $entry['from'];
            $absolute = "{$providerPath}/{$from}";

            $pairs = match (true) {
                is_file($absolute) => [$from => $entry['to']],
                is_dir($absolute) => $this->expandDirectory($absolute, $from, $entry['to'], $manifest['exclude']),
                default => throw new RuntimeException(
                    Message::MANIFEST_COPY_ENTRY_NOT_FOUND->getMessage($from, $providerPath),
                ),
            };

            foreach ($pairs as $source => $destination) {
                // Assigning an existing key keeps its position, so a later entry overrides an earlier one in place.
                $mappings[$destination] = new FileMapping(
                    $destination,
                    (string) $source,
                    Glob::lookup($manifest['modes'], $destination) ?? FileMode::Replace,
                    $providerName,
                    $providerPath,
                    Glob::lookup($manifest['merge'] ?? [], $destination),
                );
            }
        }

        return array_values($mappings);
    }

    /**
     * Returns whether the directory walk accepts `$current`: files always, directories only when no `<dir>/**`
     * exclude prunes them.
     *
     * @param SplFileInfo $current Entry the directory iterator is visiting.
     * @param string $absolutePrefix Walked directory on disk, without trailing slash.
     * @param string $relativePrefix Provider-relative path of the walked directory, or an empty string for the root.
     * @param list<string> $userExcludes Manifest `exclude` patterns.
     *
     * @return bool `true` when the entry is a file or a directory the walk may descend into, `false` otherwise.
     */
    private function acceptIteratorEntry(
        SplFileInfo $current,
        string $absolutePrefix,
        string $relativePrefix,
        array $userExcludes,
    ): bool {
        // Files pass through unconditionally: a file named `secrets` is not pruned by a `secrets/**` exclude.
        if (!$current->isDir()) {
            return true;
        }

        $directory = self::relativise($current, $absolutePrefix, $relativePrefix);

        return !Glob::prunes(DefaultExcludes::PATTERNS, $directory) && !Glob::prunes($userExcludes, $directory);
    }

    /**
     * Returns the files under a `copy[]` directory with their destinations, by replacing the source directory prefix
     * with the destination prefix of the entry.
     *
     * @param string $absoluteDir Absolute path of the directory.
     * @param string $from Provider-relative `from` path of the entry.
     * @param string $to Project-relative `to` path of the entry.
     * @param list<string> $userExcludes Manifest `exclude` patterns.
     *
     * @return array<array-key, string> Project-relative destinations keyed by sorted provider-relative source;
     * integer-like sources (`"123"`) are integer keys.
     */
    private function expandDirectory(string $absoluteDir, string $from, string $to, array $userExcludes): array
    {
        // A trailing slash (`config/`) would otherwise leave `config//file` paths that excludes do not match.
        $sourcePrefix = rtrim($from, '/');

        $sourcePrefix = $sourcePrefix === '.' ? '' : $sourcePrefix;

        $destPrefix = rtrim($to, '/');

        $destPrefix = $destPrefix === '.' ? '' : $destPrefix;

        $pairs = [];

        foreach ($this->walk($absoluteDir, $sourcePrefix, $userExcludes) as $source) {
            $pairs[$source] = self::translate($source, $sourcePrefix, $destPrefix);
        }

        return $pairs;
    }

    /**
     * Returns the provider-relative path of a walked entry, with forward slashes.
     *
     * @param SplFileInfo $entry Entry under the walked directory, joined with forward slashes.
     * @param string $absolutePrefix Walked directory on disk, without trailing slash.
     * @param string $relativePrefix Provider-relative path of the walked directory, or an empty string for the root.
     *
     * @return string Provider-relative path of the entry.
     */
    private static function relativise(SplFileInfo $entry, string $absolutePrefix, string $relativePrefix): string
    {
        $tail = substr($entry->getPathname(), strlen($absolutePrefix) + 1);

        return $relativePrefix === '' ? $tail : "{$relativePrefix}/{$tail}";
    }

    /**
     * Maps a walked source path to its destination by replacing the source directory prefix with the destination
     * prefix of the `copy[]` entry.
     *
     * @param string $source Provider-relative source path.
     * @param string $sourcePrefix Source directory without trailing slash, or an empty string for the provider root.
     * @param string $destPrefix Destination directory without trailing slash, or an empty string for the project root.
     *
     * @return string Project-relative destination path.
     */
    private static function translate(string $source, string $sourcePrefix, string $destPrefix): string
    {
        if ($sourcePrefix === '') {
            return $destPrefix === '' ? $source : "{$destPrefix}/{$source}";
        }

        $tail = substr($source, strlen($sourcePrefix) + 1);

        return $destPrefix === '' ? $tail : "{$destPrefix}/{$tail}";
    }

    /**
     * Returns the files under a `copy[]` directory that match neither a default nor a manifest exclude.
     *
     * @param string $absoluteDir Absolute path of the directory.
     * @param string $relativePrefix Provider-relative path of the directory, or an empty string for the root.
     * @param list<string> $userExcludes Manifest `exclude` patterns.
     *
     * @return list<string> Sorted provider-relative file paths.
     */
    private function walk(string $absoluteDir, string $relativePrefix, array $userExcludes): array
    {
        $results = [];

        $absolutePrefix = rtrim($absoluteDir, '/');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator(
                    $absoluteDir,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS,
                ),
                fn(SplFileInfo $current): bool => $this->acceptIteratorEntry(
                    $current,
                    $absolutePrefix,
                    $relativePrefix,
                    $userExcludes,
                ),
            ),
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $relative = self::relativise($entry, $absolutePrefix, $relativePrefix);

            if (Glob::matchesAny(DefaultExcludes::PATTERNS, $relative) || Glob::matchesAny($userExcludes, $relative)) {
                continue;
            }

            $results[] = $relative;
        }

        // Directory entry order depends on the filesystem; sorting keeps the mappings reproducible.
        sort($results);

        return $results;
    }
}
