<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\{ExitCode, Message, OutputWriter};
use PHPForge\Scaffold\Exception\Message as ExceptionMessage;
use PHPForge\Scaffold\Filesystem;
use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Merge\MergeStrategyKind;
use PHPForge\Scaffold\Scaffold\ExpectedState;
use PHPForge\Scaffold\Scaffold\Lock\{Hasher, LockFile};
use PHPForge\Scaffold\Scaffold\Modes\{AppendMode, ApplyOutcome, PrependMode, ReplaceMode};
use PHPForge\Scaffold\Scaffold\PathResolver;
use PHPForge\Scaffold\Security\PathValidator;
use RuntimeException;
use Throwable;

use function file_exists;
use function is_file;

/**
 * Rewrites tracked files from their provider stubs; without `force` it merges `append` and `prepend` files and skips
 * ejected, adopted, user-modified and existing `preserve` files; it always skips orphaned files.
 *
 * @phpstan-import-type LockEntry from LockFile
 */
final class ReapplyService
{
    /**
     * Reapplies the tracked files that match the filters and records their new hashes in `scaffold-lock.json`, or only
     * prints what would change with `$dryRun`, ending with a summary line.
     *
     * @param ProjectContext $context Project to reapply.
     * @param OutputWriter $out Output sink.
     * @param string $file Destination path to reapply, or an empty string for every tracked file.
     * @param string $provider Provider package name to reapply, or an empty string for every provider.
     * @param bool $force Whether to overwrite every matching file with its stub, including ejected, adopted,
     * user-modified, `preserve`, `append` and `prepend` files, and to clear the `ejected` and `adopted` flags.
     * @param bool $dryRun Whether to print the diff of every file that would change instead of writing anything.
     * @param bool $exitCode Whether a dry run returns `1` when any file would change.
     *
     * @throws RuntimeException when the lock file cannot be read or written.
     *
     * @return int `1` when a filter matches no tracked file or an entry was skipped for an error (unsafe entry,
     * provider not allowed, missing stub, unknown mode, read, hash or write failure), or with `$dryRun` and `$exitCode`
     * when any file would change; `0` otherwise, including user-modified, `preserve`, ejected and orphaned skips.
     */
    public function run(
        ProjectContext $context,
        OutputWriter $out,
        string $file = '',
        string $provider = '',
        bool $force = false,
        bool $dryRun = false,
        bool $exitCode = false,
    ): int {
        $projectRoot = $context->projectRoot;

        $lock = new LockFile($projectRoot);

        $data = $lock->read();

        $expected = ExpectedState::build($context, $data['providers']);

        $validator = new PathValidator();
        $summary = new ReapplySummary();

        $updatedFiles = $data['files'];

        $anyUpdated = false;
        $anyMatched = false;

        foreach ($data['files'] as $destination => $entry) {
            // PHP turns integer-like destinations ("123") into integer array keys.
            $destination = (string) $destination;

            if ($file !== '' && $destination !== $file) {
                continue;
            }

            if ($provider !== '' && $entry['provider'] !== $provider) {
                continue;
            }

            $anyMatched = true;

            if (!$context->config->isAllowed($entry['provider'])) {
                $out->writeStderr(
                    Message::COMMAND_PROVIDER_NOT_ALLOWED->getMessage($destination, $entry['provider']),
                );

                $summary->errors++;

                continue;
            }

            $resolved = $context->providerRoot($entry['provider'], $data['providers'][$entry['provider']] ?? null);

            $providerRoot = $resolved['root'];

            if ($resolved['warning'] !== null) {
                $out->writeStderr($resolved['warning']);
            }

            try {
                $validator->validateDestination($destination, $projectRoot);
            } catch (RuntimeException $e) {
                $out->writeStderr(
                    Message::REAPPLY_UNSAFE_ENTRY->getMessage($destination, $e->getMessage()),
                );

                $summary->errors++;

                continue;
            }

            if (!$force && ($entry['ejected'] ?? false) === true) {
                $out->writeStderr(
                    Message::REAPPLY_EJECTED->getMessage($destination),
                );
                $summary->skip('ejected');

                continue;
            }

            if ($expected->mapping($destination) === null && !$expected->hasFailed($entry['provider'])) {
                $out->writeStderr(
                    Message::REAPPLY_ORPHANED->getMessage($destination),
                );
                $summary->skip('orphaned');

                continue;
            }

            try {
                // The source is validated after the orphan check: the root of an uninstalled provider does not exist.
                $validator->validateSource($entry['source'], $providerRoot);
            } catch (RuntimeException $e) {
                $out->writeStderr(
                    Message::REAPPLY_UNSAFE_ENTRY->getMessage($destination, $e->getMessage()),
                );

                $summary->errors++;

                continue;
            }

            $merge = !$force
                && ($entry['mode'] === FileMode::Append->value || $entry['mode'] === FileMode::Prepend->value);
            $destPath = PathResolver::destination($projectRoot, $destination);
            $stubPath = PathResolver::source($providerRoot, $entry['source']);

            if (!$force && $entry['mode'] === FileMode::Preserve->value && is_file($destPath)) {
                $out->writeStderr(
                    Message::REAPPLY_PRESERVE->getMessage($destination),
                );
                $summary->skip('preserve');

                continue;
            }

            if (!is_file($stubPath)) {
                $out->writeStderr(
                    Message::REAPPLY_STUB_NOT_FOUND->getMessage($stubPath),
                );

                $summary->errors++;

                continue;
            }

            try {
                $mapping = self::mapping($destination, $entry, $providerRoot, $merge, $out);

                if (!$merge && !$force && self::isUserModified($destPath, $entry)) {
                    $out->writeStderr(
                        Message::REAPPLY_USER_MODIFIED->getMessage($destination),
                    );
                    $summary->skip('user-modified');

                    continue;
                }

                if ($dryRun) {
                    $preview = $merge
                        ? (new ApplyPreviewer())->preview($mapping, $projectRoot, $entry)
                        : self::stubPreview($mapping, $destPath, $stubPath);

                    if ((new DiffService())->renderPreview($preview, $out)) {
                        $summary->written++;
                    } else {
                        $summary->unchanged++;
                    }

                    continue;
                }

                $updated = $merge
                    ? $this->merge($mapping, $entry, $projectRoot, $stubPath, $summary, $out)
                    : $this->write($mapping, $entry, $projectRoot, $destPath, $summary, $out);
            } catch (Throwable $e) {
                $out->writeStderr(
                    Message::REAPPLY_FAILED->getMessage($destination, $e->getMessage()),
                );

                $summary->errors++;

                continue;
            }

            if ($updated !== $entry) {
                $updatedFiles[$destination] = $updated;
                $anyUpdated = true;
            }
        }

        if (($file !== '' || $provider !== '') && !$anyMatched) {
            $out->writeStderr(
                Message::REAPPLY_NO_MATCH->getMessage(),
            );

            return ExitCode::Error->value;
        }

        if ($anyUpdated && !$dryRun) {
            $lock->write(['providers' => $data['providers'], 'files' => $updatedFiles]);
        }

        $out->writeStdout(
            $summary->line($dryRun),
        );

        if ($summary->errors > 0) {
            return ExitCode::Error->value;
        }

        return $dryRun && $exitCode && $summary->written > 0 ? ExitCode::Error->value : ExitCode::Ok->value;
    }

    /**
     * Returns the entry with new hashes, without `ejected` and `adopted`, keeping `merge`.
     *
     * @param LockEntry $entry Current lock entry.
     * @param string $hash Destination hash after the write.
     * @param string $stubHash Provider source hash.
     *
     * @return LockEntry Updated entry.
     */
    private static function entry(array $entry, string $hash, string $stubHash): array
    {
        $updated = [
            'hash' => $hash,
            'provider' => $entry['provider'],
            'source' => $entry['source'],
            'mode' => $entry['mode'],
            'stub-hash' => $stubHash,
        ];

        if (isset($entry['merge'])) {
            $updated['merge'] = $entry['merge'];
        }

        return $updated;
    }

    /**
     * Returns whether an existing destination is adopted or differs from its recorded hash.
     *
     * @param string $destPath Absolute path to the destination.
     * @param LockEntry $entry Lock entry of the destination.
     *
     * @throws RuntimeException when the destination cannot be hashed.
     *
     * @return bool `true` when the destination is a file that is adopted or changed since the last apply, `false`
     * otherwise.
     */
    private static function isUserModified(string $destPath, array $entry): bool
    {
        return is_file($destPath)
            && (($entry['adopted'] ?? false) === true || Hasher::hash($destPath) !== $entry['hash']);
    }

    /**
     * Builds the mapping of a lock entry, warning about an unknown recorded merge strategy of a merged entry.
     *
     * @param string $destination Destination path relative to the project root.
     * @param LockEntry $entry Lock entry of the destination.
     * @param string $providerRoot Absolute path to the provider root.
     * @param bool $merge Whether the entry is merged with its `append` or `prepend` mode.
     * @param OutputWriter $out Output sink for the unknown merge strategy warning.
     *
     * @throws RuntimeException when the recorded mode is unknown.
     *
     * @return FileMapping Mapping of the entry, without a merge strategy when it is not merged or none or an unknown
     * one is recorded.
     */
    private static function mapping(
        string $destination,
        array $entry,
        string $providerRoot,
        bool $merge,
        OutputWriter $out,
    ): FileMapping {
        $strategy = null;

        if ($merge && isset($entry['merge'])) {
            $strategy = MergeStrategyKind::tryFrom($entry['merge']);

            if ($strategy === null) {
                $out->writeStderr(
                    Message::REAPPLY_MERGE_STRATEGY_UNKNOWN->getMessage($entry['merge'], $destination),
                );
            }
        }

        return new FileMapping(
            $destination,
            $entry['source'],
            FileMode::tryFrom($entry['mode'])
                ?? throw new RuntimeException(ExceptionMessage::LOCK_MODE_UNKNOWN->getMessage($entry['mode'])),
            $entry['provider'],
            $providerRoot,
            $strategy,
        );
    }

    /**
     * Runs the `append` or `prepend` mode for a lock entry, reports the outcome, and refreshes the entry `stub-hash`.
     *
     * @param FileMapping $mapping Mapping of the entry.
     * @param LockEntry $entry Lock entry of the destination.
     * @param string $projectRoot Absolute path to the project root.
     * @param string $stubPath Absolute path to the provider stub.
     * @param ReapplySummary $summary Run counters, updated in place.
     * @param OutputWriter $out Output sink.
     *
     * @throws RuntimeException when a file cannot be read, written or hashed, or a directory cannot be created.
     *
     * @return LockEntry Entry to record.
     */
    private function merge(
        FileMapping $mapping,
        array $entry,
        string $projectRoot,
        string $stubPath,
        ReapplySummary $summary,
        OutputWriter $out,
    ): array {
        $mode = $mapping->mode === FileMode::Append ? new AppendMode() : new PrependMode();

        $result = $mode->apply($mapping, $projectRoot, $entry['hash']);
        $stubHash = Hasher::hash($stubPath);

        if ($result->outcome === ApplyOutcome::Written) {
            $out->writeStdout(
                Message::REAPPLY_APPLIED->getMessage($mapping->destination),
            );

            $summary->written++;

            return self::entry($entry, $result->newHash, $stubHash);
        }

        $out->writeStdout(
            Message::REAPPLY_ALREADY_UP_TO_DATE->getMessage($mapping->destination),
        );

        $summary->unchanged++;

        $updated = $entry;
        $updated['stub-hash'] = $stubHash;

        return $updated;
    }

    /**
     * Returns the preview of a stub write that a dry run skips.
     *
     * @param FileMapping $mapping Mapping of the entry.
     * @param string $destPath Absolute path to the destination.
     * @param string $stubPath Absolute path to the provider stub.
     *
     * @throws RuntimeException when the destination or the stub cannot be read.
     *
     * @return ApplyPreview Current destination content and the stub content that the write would copy.
     */
    private static function stubPreview(FileMapping $mapping, string $destPath, string $stubPath): ApplyPreview
    {
        $exists = is_file($destPath);

        return new ApplyPreview(
            $mapping,
            $exists,
            $exists
                ? Filesystem::read($destPath, ExceptionMessage::SERVICE_CURRENT_FILE_UNREADABLE->getMessage($destPath))
                : '',
            Filesystem::read($stubPath, ExceptionMessage::SERVICE_STUB_UNREADABLE->getMessage($stubPath)),
        );
    }

    /**
     * Overwrites the destination with its stub through the `replace` mode and reports the write.
     *
     * @param FileMapping $mapping Mapping of the entry.
     * @param LockEntry $entry Lock entry of the destination.
     * @param string $projectRoot Absolute path to the project root.
     * @param string $destPath Absolute path to the destination.
     * @param ReapplySummary $summary Run counters, updated in place.
     * @param OutputWriter $out Output sink.
     *
     * @throws RuntimeException when a file cannot be copied or hashed, or a directory cannot be created.
     *
     * @return LockEntry Entry to record.
     */
    private function write(
        FileMapping $mapping,
        array $entry,
        string $projectRoot,
        string $destPath,
        ReapplySummary $summary,
        OutputWriter $out,
    ): array {
        // The destination is pristine or forced: passing its current hash lets the stub overwrite it without warning.
        $result = (new ReplaceMode())->apply(
            $mapping,
            $projectRoot,
            file_exists($destPath) ? Hasher::hash($destPath) : null,
        );

        if ($result->warning !== null) {
            $out->writeStderr(
                Message::SCAFFOLD_PREFIXED->getMessage($result->warning),
            );
        }

        $summary->written++;

        $out->writeStdout(
            Message::REAPPLY_APPLIED->getMessage($mapping->destination),
        );

        // The destination is now a copy of the stub, so both hashes are the hash of the stub.
        return self::entry($entry, $result->newHash, $result->newHash);
    }
}
