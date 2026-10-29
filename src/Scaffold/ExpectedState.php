<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold;

use LogicException;
use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Manifest\{FileMapping, ManifestExpander, ManifestLoader, ManifestSchema};
use Throwable;

use function array_slice;
use function count;
use function in_array;
use function is_string;

/**
 * Represents the file mappings that the allowed and installed providers ship now, merged with the rule of
 * {@see ScaffoldRunner}.
 */
final readonly class ExpectedState
{
    /**
     * Maximum number of orphaned files that {@see self::orphanReport()} lists by name.
     */
    private const int ORPHAN_LIST_LIMIT = 10;

    /**
     * @param array<array-key, FileMapping> $mappings Expected mappings keyed by destination.
     * @param list<string> $failedProviders Allowed and installed providers whose manifest could not be loaded.
     * @param list<string> $warnings Messages about provider roots and manifests, prefixed with `[scaffold]`.
     */
    public function __construct(
        private array $mappings,
        private array $failedProviders = [],
        public array $warnings = [],
    ) {}

    /**
     * Returns every expected mapping keyed by destination, in `allowed-packages` and manifest order.
     *
     * @return array<array-key, FileMapping> Mappings keyed by destination; integer-like destinations (`"123"`) are
     * integer keys.
     */
    public function all(): array
    {
        return $this->mappings;
    }

    /**
     * Loads the manifests of every allowed and installed provider, in `allowed-packages` order.
     *
     * @param ProjectContext $context Project whose providers are loaded.
     * @param array<string, mixed> $lockProviders `providers` map of `scaffold-lock.json`, used for recorded paths.
     * @param ManifestLoader $loader Loads the provider manifests into file mappings.
     *
     * @return self Merged mappings, the providers whose manifest failed to load, and their warnings.
     */
    public static function build(
        ProjectContext $context,
        array $lockProviders,
        ManifestLoader $loader = new ManifestLoader(new ManifestSchema(), new ManifestExpander()),
    ): self {
        $resolved = $context->providers($lockProviders);

        $merged = [];
        $failed = [];
        $warnings = [];

        foreach ($resolved['providers'] as $name => $provider) {
            if (isset($resolved['warnings'][$name])) {
                $warnings[] = $resolved['warnings'][$name];
            }

            try {
                $mappings = $loader->loadFromPath(
                    $name,
                    $provider->installPath ?? throw new LogicException(
                        Message::PROVIDER_INSTALL_PATH_MISSING->getMessage($name),
                    ),
                );
            } catch (Throwable $e) {
                $failed[] = $name;
                $warnings[] = Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage($name, $e->getMessage());

                continue;
            }

            MappingMerger::merge($merged, $mappings);
        }

        return new self($merged, $failed, $warnings);
    }

    /**
     * Returns the allowed and installed providers whose manifest could not be loaded.
     *
     * @return list<string> Provider names in `allowed-packages` order.
     */
    public function failedProviders(): array
    {
        return $this->failedProviders;
    }

    /**
     * Returns whether the manifest of `$provider` could not be loaded.
     *
     * @param string $provider Provider package name.
     *
     * @return bool `true` when the provider manifest failed to load, `false` otherwise.
     */
    public function hasFailed(string $provider): bool
    {
        return in_array($provider, $this->failedProviders, true);
    }

    /**
     * Returns the expected mapping of `$destination`, or `null` when no allowed and installed provider ships it.
     *
     * @param string $destination Destination path relative to the project root.
     *
     * @return FileMapping|null Expected mapping, or `null` when no allowed and installed provider ships it.
     */
    public function mapping(string $destination): FileMapping|null
    {
        return $this->mappings[$destination] ?? null;
    }

    /**
     * Returns the stderr lines that announce the orphans of `$files`: a header with the count and the `prune` hint,
     * at most ten destinations with their provider, and the number of the others.
     *
     * @param array<array-key, array<array-key, mixed>> $files `files` map of `scaffold-lock.json`.
     *
     * @return list<string> Lines prefixed with `[scaffold]`; empty when there is no orphan.
     */
    public function orphanReport(array $files): array
    {
        $orphans = $this->orphans($files);

        $total = count($orphans);

        if ($total === 0) {
            return [];
        }

        $lines = [
            ConsoleMessage::SCAFFOLD_ORPHAN_HEADER->getMessage(
                $total,
                ConsoleMessage::plural($total, ConsoleMessage::FRAGMENT_FILE, ConsoleMessage::FRAGMENT_FILES),
            ),
        ];

        foreach (array_slice($orphans, 0, self::ORPHAN_LIST_LIMIT, true) as $destination => $entry) {
            $provider = $entry['provider'] ?? null;

            $lines[] = ConsoleMessage::SCAFFOLD_ORPHAN_ENTRY->getMessage(
                (string) $destination,
                is_string($provider) ? $provider : ConsoleMessage::FRAGMENT_UNKNOWN_PROVIDER->getMessage(),
            );
        }

        if ($total > self::ORPHAN_LIST_LIMIT) {
            $lines[] = ConsoleMessage::SCAFFOLD_ORPHAN_MORE->getMessage($total - self::ORPHAN_LIST_LIMIT);
        }

        return $lines;
    }

    /**
     * Returns the lock entries that no allowed and installed provider ships anymore; ejected entries and entries of a
     * provider whose manifest could not be loaded are never orphans.
     *
     * @template TEntry of array<array-key, mixed>
     *
     * @param array<array-key, TEntry> $files `files` map of `scaffold-lock.json`.
     *
     * @return array<array-key, TEntry> Orphan entries keyed by destination, in lock order; integer-like destinations
     * (`"123"`) are integer keys.
     */
    public function orphans(array $files): array
    {
        $orphans = [];

        foreach ($files as $destination => $entry) {
            // PHP turns integer-like destinations ("123") into integer array keys.
            if (($entry['ejected'] ?? false) === true || $this->mapping((string) $destination) !== null) {
                continue;
            }

            $provider = $entry['provider'] ?? null;

            if (is_string($provider) && $this->hasFailed($provider)) {
                continue;
            }

            $orphans[$destination] = $files[$destination];
        }

        return $orphans;
    }
}
