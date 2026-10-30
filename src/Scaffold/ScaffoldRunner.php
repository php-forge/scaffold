<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold;

use PHPForge\Scaffold\Config\{ProjectConfig, ProviderPackage};
use PHPForge\Scaffold\Console\{Message as ConsoleMessage, OutputWriter};
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Exception\ScaffoldException;
use PHPForge\Scaffold\Manifest\{FileMapping, FileMode, ManifestLoader};
use PHPForge\Scaffold\Scaffold\Lock\{Hasher, LockFile};
use PHPForge\Scaffold\Scaffold\Modes\{
    AppendMode,
    ApplyOutcome,
    ApplyResult,
    ModeInterface,
    PrependMode,
    PreserveMode,
    ReplaceMode,
};
use Throwable;

use function count;
use function file_exists;
use function is_array;
use function is_string;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strlen;
use function substr;

/**
 * Applies the files of every allowed and installed provider to the project and records them in `scaffold-lock.json`,
 * without depending on Composer's IO or package classes.
 *
 * @phpstan-import-type LockEntry from LockFile
 */
final readonly class ScaffoldRunner
{
    /**
     * @var array<string, ModeInterface> Mode implementations keyed by the backing value of {@see FileMode}.
     */
    private array $modes;

    /**
     * Creates a runner and its mode implementations.
     *
     * @param ManifestLoader $loader Loads provider manifests into file mappings.
     * @param FileApplier $applier Validates and applies each mapping.
     * @param LockFile $lockFile Lock file of the project.
     * @param OutputWriter $out Output sink: notices on stdout, warnings and errors on stderr.
     * @param ScaffoldSummary $summary Counters that the runs of this runner update.
     */
    public function __construct(
        private ManifestLoader $loader,
        private FileApplier $applier,
        private LockFile $lockFile,
        private OutputWriter $out,
        private ScaffoldSummary $summary,
    ) {
        $this->modes = [
            FileMode::Replace->value => new ReplaceMode(),
            FileMode::Preserve->value => new PreserveMode(),
            FileMode::Append->value => new AppendMode(),
            FileMode::Prepend->value => new PrependMode(),
        ];
    }

    /**
     * Applies the files of every provider listed in `allowed-packages` and installed, and records them in
     * `scaffold-lock.json`; a later provider overrides an earlier one per destination, and ejected files stay
     * untouched.
     *
     * @param ProjectConfig $config Consumer settings that list the allowed providers in order.
     * @param array<string, ProviderPackage> $providers Installed packages keyed by package name.
     * @param string $projectRoot Absolute path to the project root.
     * @param bool $force Whether to overwrite user-modified and adopted `replace` destinations.
     *
     * @throws ScaffoldException when the manifest of an allowed and installed provider cannot be loaded; the message
     * is written to stderr first and nothing is applied.
     * @throws \RuntimeException when the lock file cannot be read or written.
     *
     * @return ScaffoldSummary Counters of the run; a mapping that fails to apply is reported, counted in
     * {@see ScaffoldSummary::$errors}, and does not stop the run.
     */
    public function run(
        ProjectConfig $config,
        array $providers,
        string $projectRoot,
        bool $force = false,
    ): ScaffoldSummary {
        $merged = [];
        $providerEntries = [];

        foreach ($config->allowedPackages as $name) {
            $provider = $providers[$name] ?? null;

            if ($provider === null) {
                $this->out->writeStderr(
                    ConsoleMessage::SCAFFOLD_PROVIDER_NOT_INSTALLED->getMessage($name),
                );

                $this->summary->warnings++;

                continue;
            }

            if ($provider->installPath === null) {
                $this->out->writeStderr(
                    ConsoleMessage::SCAFFOLD_PROVIDER_INSTALL_PATH_MISSING->getMessage($name),
                );

                $this->summary->warnings++;

                continue;
            }

            try {
                $mappings = $provider->extra === null
                    ? $this->loader->loadFromPath($name, $provider->installPath)
                    : $this->loader->loadFromExtra($provider->extra, $name, $provider->installPath);
            } catch (Throwable $e) {
                $message = Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage($name, $e->getMessage());

                $this->out->writeStderr(
                    $message,
                );

                $this->summary->errors++;

                // Applying the other providers could write the wrong owner of a shared destination: abort first.
                throw new ScaffoldException($message, previous: $e);
            }

            // Recorded only after a successful load, so a failing provider never advances its version in the lock.
            $providerEntries[$name] = [
                'version' => $provider->prettyVersion,
                // Both sides are canonical, so symlinked or short-name roots still yield a relative path.
                'path' => self::toProjectRelative(
                    PathResolver::realpathOrFallback($provider->installPath),
                    PathResolver::realpathOrFallback($projectRoot),
                ),
            ];

            if ($mappings === []) {
                $this->out->writeStderr(
                    ConsoleMessage::SCAFFOLD_PROVIDER_MAPPINGS_EMPTY->getMessage($name),
                );

                $this->summary->warnings++;

                continue;
            }

            foreach (MappingMerger::merge($merged, $mappings) as [$mapping, $previous]) {
                $this->out->writeStdout(
                    ConsoleMessage::SCAFFOLD_OVERRIDE->getMessage(
                        $mapping->destination,
                        $mapping->providerName,
                        $previous->providerName,
                    ),
                );
            }
        }

        if ($merged === []) {
            if ($this->lockFile->exists()) {
                $this->reportOrphans(new ExpectedState([]), $this->lockFile->read()['files']);
            }

            return $this->summary;
        }

        $lockData = $this->lockFile->read();

        $dirty = false;
        $conflictsBefore = $this->summary->conflict;

        foreach ($providerEntries as $providerName => $entry) {
            $existing = $lockData['providers'][$providerName] ?? null;

            if (!is_array($existing) || $existing !== $entry) {
                $lockData['providers'][$providerName] = $entry;
                $dirty = true;
            }
        }

        // Keys are not used: PHP turns integer-like destinations ("123") into integer array keys.
        foreach ($merged as $mapping) {
            $destination = $mapping->destination;
            $existing = $lockData['files'][$destination] ?? null;

            // Ejected files belong to the user: never touched, never rehashed.
            if (($existing['ejected'] ?? false) === true) {
                $this->summary->unchanged++;

                continue;
            }

            try {
                $entry = $this->applyMapping($mapping, $existing, $projectRoot, $force);

                if ($entry !== null && $entry !== $existing) {
                    $lockData['files'][$destination] = $entry;
                    $dirty = true;
                }
            } catch (Throwable $e) {
                $this->out->writeStderr(
                    ConsoleMessage::SCAFFOLD_APPLY_FAILED->getMessage($destination, $e->getMessage()),
                );

                $this->summary->errors++;
            }
        }

        if ($dirty) {
            $this->lockFile->write($lockData);
        }

        $conflicts = $this->summary->conflict - $conflictsBefore;

        if ($conflicts > 0) {
            $this->out->writeStderr(
                ConsoleMessage::SCAFFOLD_CONFLICTS->getMessage(
                    $conflicts,
                    ConsoleMessage::plural(
                        $conflicts,
                        ConsoleMessage::FRAGMENT_CONFLICT_FILE,
                        ConsoleMessage::FRAGMENT_CONFLICT_FILES,
                    ),
                ),
            );
        }

        $this->reportOrphans(new ExpectedState($merged), $lockData['files']);

        return $this->summary;
    }

    /**
     * Applies one mapping and returns the lock entry to record for it.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param LockEntry|null $existing Current lock entry, or `null` when the destination is untracked.
     * @param string $projectRoot Absolute path to the project root.
     * @param bool $force Whether to overwrite a user-modified or adopted `replace` destination.
     *
     * @throws \RuntimeException when a path fails validation or a file cannot be read, written or hashed.
     *
     * @return LockEntry|null Entry to record, or `null` when the current entry must stay unchanged.
     */
    private function applyMapping(
        FileMapping $mapping,
        array|null $existing,
        string $projectRoot,
        bool $force,
    ): array|null {
        $destination = $mapping->destination;
        $destPath = PathResolver::destination($projectRoot, $destination);

        $existed = file_exists($destPath);
        $hashAtScaffold = is_string($existing['hash'] ?? null) ? $existing['hash'] : null;

        if ($mapping->mode === FileMode::Replace && $existed) {
            if ($force) {
                // A forced replace treats the current content as pristine, so the stub overwrites it silently.
                $hashAtScaffold = Hasher::hash($destPath);

                unset($existing['adopted']);
            } elseif (($existing['adopted'] ?? false) === true) {
                $this->out->writeStderr(
                    ConsoleMessage::SCAFFOLD_ADOPTED_SKIPPED->getMessage($destination),
                );

                $this->summary->warnings++;

                if ($this->stubChanged($mapping, $existing)) {
                    $this->summary->conflict++;
                }

                return null;
            }
        }

        $result = $this->applier->apply($mapping, $projectRoot, $this->modes[$mapping->mode->value], $hashAtScaffold);

        $this->count($result, $existed ? $hashAtScaffold : null);

        if ($result->outcome === ApplyOutcome::Skipped && $this->stubChanged($mapping, $existing)) {
            // Only a user-modified `replace` destination is skipped without a hash.
            if ($result->newHash === '') {
                // The consumer changed the file and the provider changed its stub: the update is not taken.
                $this->summary->conflict++;
            } elseif ($mapping->mode === FileMode::Preserve) {
                $this->summary->outdated++;
            }
        }

        return $this->buildEntry($mapping, $result, $existing);
    }

    /**
     * Returns the lock entry to record for an applied mapping, with a fresh `stub-hash`.
     *
     * @param FileMapping $mapping Applied file mapping.
     * @param ApplyResult $result Result returned by the mode for the mapping.
     * @param array<array-key, mixed>|null $existing Current lock entry, or `null` when untracked.
     *
     * @throws \RuntimeException when the provider source cannot be hashed.
     *
     * @return LockEntry|null Entry to record, or `null` when the current entry must stay unchanged.
     */
    private function buildEntry(FileMapping $mapping, ApplyResult $result, array|null $existing): array|null
    {
        if ($result->outcome === ApplyOutcome::Skipped && $result->newHash === '') {
            return null;
        }

        $existingHash = $existing['hash'] ?? null;
        $written = $result->outcome === ApplyOutcome::Written;

        $entry = [
            'hash' => !$written && is_string($existingHash) ? $existingHash : $result->newHash,
            'provider' => $mapping->providerName,
            'source' => $mapping->source,
            'mode' => $mapping->mode->value,
            'stub-hash' => Hasher::hash(PathResolver::source($mapping->providerPath, $mapping->source)),
        ];

        if ($mapping->mergeStrategy !== null) {
            $entry['merge'] = $mapping->mergeStrategy->value;
        }

        $adopted = ($existing['adopted'] ?? false) === true
            || ($existing === null && $mapping->mode === FileMode::Preserve);

        if (!$written && $adopted) {
            $entry['adopted'] = true;
        }

        return $entry;
    }

    /**
     * Counts an apply result as written, unchanged or warned; rewriting an existing destination with the content it
     * already had counts as unchanged.
     *
     * @param ApplyResult $result Result returned by the mode for the mapping.
     * @param string|null $previousHash Recorded hash of a destination that existed before the apply, or `null`.
     */
    private function count(ApplyResult $result, string|null $previousHash): void
    {
        $rewritten = $previousHash !== null && $previousHash === $result->newHash;

        if ($result->outcome === ApplyOutcome::Written && !$rewritten) {
            $this->summary->written++;
        } elseif ($result->warning === null) {
            $this->summary->unchanged++;
        }

        if ($result->warning !== null) {
            $this->summary->warnings++;
        }
    }

    /**
     * Returns whether `$haystack` starts with `$needle`, ignoring case when `$caseInsensitive` is `true`.
     *
     * @param string $haystack String to inspect.
     * @param string $needle Prefix to look for.
     * @param bool $caseInsensitive Whether to compare ignoring case.
     *
     * @return bool `true` when `$haystack` starts with `$needle`, `false` otherwise.
     */
    private static function prefixMatches(string $haystack, string $needle, bool $caseInsensitive): bool
    {
        return $caseInsensitive
            ? stripos($haystack, $needle) === 0
            : str_starts_with($haystack, $needle);
    }

    /**
     * Lists on stderr the lock entries that no allowed and installed provider ships anymore, with the `prune` hint;
     * nothing is deleted.
     *
     * @param ExpectedState $expected Destinations that the allowed and installed providers ship.
     * @param array<array-key, LockEntry> $files `files` map of `scaffold-lock.json`.
     */
    private function reportOrphans(ExpectedState $expected, array $files): void
    {
        $lines = $expected->orphanReport($files);

        if ($lines === []) {
            return;
        }

        $this->summary->orphan += count($expected->orphans($files));

        foreach ($lines as $line) {
            $this->out->writeStderr(
                $line,
            );
        }
    }

    /**
     * Returns whether the provider stub of `$mapping` differs from the `stub-hash` recorded at the last apply; `false`
     * when no `stub-hash` is recorded.
     *
     * @param FileMapping $mapping File mapping whose provider source is hashed.
     * @param array<array-key, mixed>|null $existing Current lock entry, or `null` when untracked.
     *
     * @throws \RuntimeException when the provider source cannot be hashed.
     *
     * @return bool `true` when the recorded `stub-hash` differs from the current source hash, `false` otherwise.
     */
    private function stubChanged(FileMapping $mapping, array|null $existing): bool
    {
        $recorded = $existing['stub-hash'] ?? null;

        if (!is_string($recorded)) {
            return false;
        }

        $current = Hasher::hash(PathResolver::source($mapping->providerPath, $mapping->source));

        return $recorded !== $current;
    }

    /**
     * Converts a canonical absolute path to a project-relative path with forward slashes, comparing the root prefix
     * case-insensitively on Windows.
     *
     * @param string $absolutePath Absolute path to convert.
     * @param string $projectRoot Absolute path to the project root.
     *
     * @return string Relative path, or the absolute path with forward slashes when it lies outside the project root.
     */
    private static function toProjectRelative(string $absolutePath, string $projectRoot): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $absolutePath);

        if (self::prefixMatches($normalizedPath . '/', $normalizedRoot, DIRECTORY_SEPARATOR === '\\')) {
            return substr($normalizedPath, strlen($normalizedRoot));
        }

        return $normalizedPath;
    }
}
