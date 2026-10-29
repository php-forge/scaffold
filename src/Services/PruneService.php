<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\{ExitCode, Message, OutputWriter, Table};
use PHPForge\Scaffold\Filesystem;
use PHPForge\Scaffold\Manifest\FileMode;
use PHPForge\Scaffold\Scaffold\{ExpectedState, PathResolver};
use PHPForge\Scaffold\Scaffold\Lock\{Hasher, LockFile};
use PHPForge\Scaffold\Security\PathValidator;
use RuntimeException;

use function array_column;
use function count;
use function dirname;
use function file_exists;
use function in_array;
use function is_link;

/**
 * Reports the orphaned entries of `scaffold-lock.json` and, when forced, removes them from the lock and deletes the
 * orphaned files that hold only provider content.
 *
 * @phpstan-import-type LockEntry from LockFile
 */
final class PruneService
{
    /**
     * Disk state of an orphan whose destination cannot be read.
     */
    private const string ERROR = 'error';
    /**
     * Disk state of an orphan whose content holds only provider bytes recorded at the last apply.
     */
    private const string UNMODIFIED = 'unmodified';

    /**
     * Previews or prunes the orphaned entries of the project lock, ending with a summary line.
     *
     * @param ProjectContext $context Project to prune.
     * @param OutputWriter $out Output sink.
     * @param bool $force Whether to remove the orphan entries from the lock and delete the unmodified orphan files.
     * @param bool $deleteModified Whether `$force` also deletes orphan files that hold consumer content.
     * @param bool $exitCode Whether a preview returns `1` when orphans exist.
     *
     * @throws RuntimeException when the lock file cannot be read or written.
     *
     * @return int `1` when a provider manifest cannot be loaded or a file or directory cannot be deleted, or with
     * `$exitCode` when a preview finds orphans; `0` otherwise.
     */
    public function run(
        ProjectContext $context,
        OutputWriter $out,
        bool $force = false,
        bool $deleteModified = false,
        bool $exitCode = false,
    ): int {
        $lock = new LockFile($context->projectRoot);

        $data = $lock->read();

        $expected = ExpectedState::build($context, $data['providers']);

        foreach ($expected->warnings as $warning) {
            $out->writeStderr($warning);
        }

        $errors = count($expected->failedProviders());

        /** @var array<array-key, array{provider: string, disk: string, action: string}> $rows */
        $rows = [];

        foreach ($expected->orphans($data['files']) as $destination => $entry) {
            // PHP turns integer-like destinations ("123") into integer array keys.
            $disk = $this->diskState((string) $destination, $entry, $context->projectRoot);

            if ($disk === self::ERROR) {
                $errors++;
            }

            $rows[$destination] = [
                'provider' => $entry['provider'],
                'disk' => $disk,
                'action' => self::action($disk, $deleteModified),
            ];
        }

        if ($rows === []) {
            if ($force && $this->dropStaleProviders($data, $context)) {
                $lock->write($data);
            }

            $out->writeStdout(
                Message::PRUNE_NO_ORPHANS->getMessage(),
            );

            return $errors > 0 ? ExitCode::Error->value : ExitCode::Ok->value;
        }

        if (!$force) {
            $this->renderTable($rows, $out);

            $counts = self::count($rows);

            $out->writeStdout(
                Message::PRUNE_DRY_RUN_SUMMARY->getMessage(
                    $counts['removed'],
                    $counts['kept'],
                    $errors,
                    Message::plural($errors, Message::FRAGMENT_ERROR, Message::FRAGMENT_ERRORS),
                ),
            );

            return $errors > 0 || $exitCode ? ExitCode::Error->value : ExitCode::Ok->value;
        }

        $removed = 0;
        $kept = 0;

        foreach ($rows as $key => $row) {
            // PHP turns integer-like destinations ("123") into integer array keys.
            $file = (string) $key;

            if ($row['action'] === 'delete' || $row['action'] === 'delete (modified)') {
                if (!$this->delete($file, $context->projectRoot, $out)) {
                    $errors++;

                    continue;
                }

                $out->writeStdout(
                    Message::PRUNE_DELETED->getMessage(
                        $file,
                        $row['disk'] === self::UNMODIFIED
                            ? ''
                            : Message::FRAGMENT_PARENTHESIZED->getMessage($row['disk']),
                    ),
                );
                $removed++;
            } elseif ($row['action'] === 'keep') {
                $out->writeStdout(
                    Message::PRUNE_KEPT->getMessage($file, $row['disk']),
                );

                $kept++;
            } else {
                $out->writeStdout(
                    Message::PRUNE_ENTRY_DROPPED->getMessage($file, $row['disk']),
                );

                $removed++;
            }

            unset($data['files'][$file]);
        }

        $this->dropStaleProviders($data, $context);

        $lock->write($data);

        $out->writeStdout(
            Message::PRUNE_SUMMARY->getMessage(
                $removed,
                $kept,
                $errors,
                Message::plural($errors, Message::FRAGMENT_ERROR, Message::FRAGMENT_ERRORS),
            ),
        );

        return $errors > 0 ? ExitCode::Error->value : ExitCode::Ok->value;
    }

    /**
     * Returns what `--force` does with an orphan in disk state `$disk`.
     *
     * @param string $disk Disk state of the orphan, as returned by {@see self::diskState()}.
     * @param bool $deleteModified Whether `--force` also deletes orphan files that hold consumer content.
     *
     * @return string Action shown in the table: `delete`, `delete (modified)`, `drop entry` or `keep`.
     */
    private static function action(string $disk, bool $deleteModified): string
    {
        return match ($disk) {
            self::UNMODIFIED => 'delete',
            'missing', 'unsafe' => 'drop entry',
            self::ERROR => 'keep',
            default => $deleteModified ? 'delete (modified)' : 'keep',
        };
    }

    /**
     * Returns the number of orphans that `--force` would remove and keep.
     *
     * @param array<array-key, array{provider: string, disk: string, action: string}> $rows Orphan rows keyed by
     * destination.
     *
     * @return array{removed: int, kept: int} Number of orphans that `--force` would remove and keep.
     */
    private static function count(array $rows): array
    {
        $kept = 0;

        foreach ($rows as $row) {
            if ($row['action'] === 'keep') {
                $kept++;
            }
        }

        return [
            'removed' => count($rows) - $kept,
            'kept' => $kept,
        ];
    }

    /**
     * Deletes an orphan file and the empty parent directories it leaves behind, up to the project root.
     *
     * @param string $file Destination of the orphan, relative to `$projectRoot`.
     * @param string $projectRoot Absolute path to the project root.
     * @param OutputWriter $out Output sink for deletion failures.
     *
     * @return bool `true` when the file was deleted, even if an empty parent directory could not be removed; `false`
     * otherwise.
     */
    private function delete(string $file, string $projectRoot, OutputWriter $out): bool
    {
        $path = PathResolver::destination($projectRoot, $file);

        try {
            Filesystem::remove($path, Message::PRUNE_FILE_DELETE_FAILED->getMessage($path));
        } catch (RuntimeException $e) {
            $out->writeStderr(
                $e->getMessage(),
            );

            return false;
        }

        // The relative parents end at `.`, the project root, which is never removed.
        $parent = dirname($file);

        try {
            while ($parent !== '.') {
                $dir = PathResolver::destination($projectRoot, $parent);
                $failure = Message::PRUNE_DIRECTORY_REMOVE_FAILED->getMessage($dir);

                if (is_link($dir) || !Filesystem::isEmptyDirectory($dir, $failure)) {
                    break;
                }

                Filesystem::removeDirectory($dir, $failure);

                $parent = dirname($parent);
            }
        } catch (RuntimeException $e) {
            $out->writeStderr(
                $e->getMessage(),
            );
        }

        return true;
    }

    /**
     * Returns the disk state of an orphan: `unmodified` when the file holds only the provider content recorded at the
     * last apply, `modified`, `merged` (append or prepend content mixed with consumer content), `adopted`, `missing`,
     * `unsafe` when the destination fails validation, or `error` when it cannot be read.
     *
     * @param string $destination Destination path as recorded in `scaffold-lock.json`.
     * @param LockEntry $entry Lock entry of the orphan.
     * @param string $projectRoot Absolute path to the project root.
     *
     * @return string Disk state: `unmodified`, `modified`, `merged`, `adopted`, `missing`, `unsafe` or `error`.
     */
    private function diskState(string $destination, array $entry, string $projectRoot): string
    {
        try {
            (new PathValidator())
                ->validateDestination($destination, $projectRoot);
        } catch (RuntimeException) {
            return 'unsafe';
        }

        $path = PathResolver::destination($projectRoot, $destination);

        if (!file_exists($path)) {
            return 'missing';
        }

        if (($entry['adopted'] ?? false) === true) {
            return 'adopted';
        }

        try {
            $hash = Hasher::hash($path);
        } catch (RuntimeException) {
            return self::ERROR;
        }

        if ($hash !== $entry['hash']) {
            return 'modified';
        }

        $merged = $entry['mode'] === FileMode::Append->value || $entry['mode'] === FileMode::Prepend->value;

        // An append or prepend file holds only provider bytes when it equals the stub recorded at the last apply.
        if ($merged && $hash !== ($entry['stub-hash'] ?? '')) {
            return 'merged';
        }

        return self::UNMODIFIED;
    }

    /**
     * Removes from the lock `providers` map the providers that have no file entry left and are no longer allowed or
     * installed.
     *
     * @param array{providers: array<string, mixed>, files: array<array-key, LockEntry>} $data Lock data, updated in
     * place.
     * @param ProjectContext $context Project whose allowed and installed providers are kept.
     *
     * @return bool `true` when a provider was removed, `false` otherwise.
     */
    private function dropStaleProviders(array &$data, ProjectContext $context): bool
    {
        $owners = array_column($data['files'], 'provider');

        $dropped = false;

        foreach ($data['providers'] as $name => $info) {
            if (
                in_array($name, $owners, true)
                || ($context->config->isAllowed($name) && $context->isInstalled($name, $info))
            ) {
                continue;
            }

            unset($data['providers'][$name]);

            $dropped = true;
        }

        return $dropped;
    }

    /**
     * Writes the orphan table.
     *
     * @param array<array-key, array{provider: string, disk: string, action: string}> $rows Orphan rows keyed by
     * destination.
     * @param OutputWriter $out Output sink.
     */
    private function renderTable(array $rows, OutputWriter $out): void
    {
        $cells = [];

        foreach ($rows as $file => $row) {
            $cells[] = [(string) $file, $row['provider'], $row['disk'], $row['action']];
        }

        foreach (Table::render(['File', 'Provider', 'Disk', 'With --force'], $cells) as $line) {
            $out->writeStdout(
                $line,
            );
        }
    }
}
