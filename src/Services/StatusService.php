<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

use JsonException;
use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\{ExitCode, Message as ConsoleMessage, OutputWriter, Table};
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Scaffold\ExpectedState;
use PHPForge\Scaffold\Scaffold\Lock\{Hasher, LockFile};
use PHPForge\Scaffold\Scaffold\PathResolver;
use PHPForge\Scaffold\Security\PathValidator;
use RuntimeException;
use Throwable;

use function array_column;
use function array_count_values;
use function array_diff;
use function array_filter;
use function count;
use function file_exists;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;

/**
 * Reports the state of each file tracked in `scaffold-lock.json` against the disk and the current provider stubs, and
 * the provider files not tracked yet as `pending`.
 *
 * @phpstan-import-type LockEntry from LockFile
 */
final class StatusService
{
    /**
     * Renders the status of all files tracked in `scaffold-lock.json` of the project, as a table followed by a
     * summary line or as a JSON document, with notices and provider version drift on stderr.
     *
     * @param ProjectContext $context Project to inspect.
     * @param OutputWriter $out Output sink.
     * @param bool $json Whether to print only the JSON document on stdout.
     * @param bool $exitCode Whether to return `1` when any file is neither `synced` nor `ejected`, `pending` included.
     *
     * @throws RuntimeException when the lock file cannot be read or the JSON document cannot be encoded.
     *
     * @return int `1` when any file is in the `error` state, or with `$exitCode` when any file is neither `synced` nor
     * `ejected`; `0` otherwise.
     */
    public function run(ProjectContext $context, OutputWriter $out, bool $json = false, bool $exitCode = false): int
    {
        $report = $this->evaluate($context);

        foreach ($report['notices'] as $notice) {
            $out->writeStderr(
                $notice,
            );
        }

        $statuses = $report['files'];
        $counts = self::count($statuses);

        if ($json) {
            $out->writeStdout(
                self::encodeJson($statuses, $report['providers'], $counts),
            );
        } else {
            foreach ($report['providers'] as $name => $versions) {
                $drifted = $versions['lock'] !== null
                    && $versions['installed'] !== null
                    && $versions['lock'] !== $versions['installed'];

                if ($drifted) {
                    $out->writeStderr(
                        ConsoleMessage::STATUS_PROVIDER_DRIFT->getMessage(
                            $name,
                            $versions['lock'],
                            $versions['installed'],
                        ),
                    );
                }
            }

            $this->renderTable($statuses, $counts, $out);
        }

        $states = array_column($statuses, 'status');

        if (in_array(FileStatus::Error->value, $states, true)) {
            return ExitCode::Error->value;
        }

        $unclean = array_diff($states, [FileStatus::Synced->value, FileStatus::Ejected->value]);

        return $exitCode && $unclean !== [] ? ExitCode::Error->value : ExitCode::Ok->value;
    }

    /**
     * Compares an existing destination with its lock entry and the current stub.
     *
     * @param string $destPath Absolute path to the existing destination.
     * @param LockEntry $entry Lock entry of the destination.
     * @param FileMapping $mapping Expected mapping of the destination.
     * @param string $projectRoot Absolute path to the project root.
     * @param int $lackingStubHash Number of `replace`/`preserve` entries without `stub-hash`, incremented in place.
     *
     * @throws RuntimeException when a file cannot be read or hashed, or the stub is missing.
     *
     * @return FileStatus `synced`, `outdated`, `modified` or `conflict`.
     */
    private function compare(
        string $destPath,
        array $entry,
        FileMapping $mapping,
        string $projectRoot,
        int &$lackingStubHash,
    ): FileStatus {
        if ($mapping->mode === FileMode::Append || $mapping->mode === FileMode::Prepend) {
            return (new ApplyPreviewer())
                ->preview($mapping, $projectRoot, $entry)->changes()
                    ? FileStatus::Outdated
                    : FileStatus::Synced;
        }

        $stubPath = PathResolver::source($mapping->providerPath, $mapping->source);

        $stubHash = Hasher::hash($stubPath);
        $destHash = Hasher::hash($destPath);
        $lockMatches = $destHash === $entry['hash'];

        if (isset($entry['stub-hash'])) {
            $stubChanged = $stubHash !== $entry['stub-hash'];
        } else {
            $lackingStubHash++;

            // Without a recorded stub hash, an unmodified replace file is outdated exactly when it differs from the
            // current stub.
            $stubChanged = $mapping->mode === FileMode::Replace
                && $lockMatches
                && $destHash !== $stubHash;
        }

        if (!$lockMatches) {
            return $mapping->mode === FileMode::Replace && $stubChanged ? FileStatus::Conflict : FileStatus::Modified;
        }

        return $stubChanged ? FileStatus::Outdated : FileStatus::Synced;
    }

    /**
     * Returns the number of files in each state, keyed by state value in summary order.
     *
     * @param array<array-key, array{provider: string, mode: string, status: string}> $statuses File statuses.
     *
     * @return array<string, int> Counts keyed by state value.
     */
    private static function count(array $statuses): array
    {
        $tally = array_count_values(array_column($statuses, 'status'));

        $counts = [];

        foreach (FileStatus::summaryOrder() as $state) {
            $counts[$state->value] = $tally[$state->value] ?? 0;
        }

        return $counts;
    }

    /**
     * Encodes the status report as a pretty-printed JSON document.
     *
     * @param array<array-key, array{provider: string, mode: string, status: string}> $statuses File statuses.
     * @param array<string, array{lock: string|null, installed: string|null}> $providers Provider versions.
     * @param array<string, int> $counts Counts keyed by state value.
     *
     * @throws RuntimeException when the document cannot be encoded.
     *
     * @return string JSON document with the `files`, `providers` and `summary` sections.
     */
    private static function encodeJson(array $statuses, array $providers, array $counts): string
    {
        try {
            return json_encode(
                [
                    'files' => (object) $statuses,
                    'providers' => (object) $providers,
                    'summary' => ['total' => count($statuses), ...$counts],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                Message::SERVICE_STATUS_ENCODE_FAILED->getMessage($e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Evaluates every lock entry against the disk, the expected provider mappings and the current stubs.
     *
     * @param ProjectContext $context Project to evaluate.
     *
     * @return array{
     *   files: array<array-key, array{provider: string, mode: string, status: string}>,
     *   providers: array<string, array{lock: string|null, installed: string|null}>,
     *   notices: list<string>,
     * } File statuses, provider versions from the lock and the installed packages, and stderr notices.
     */
    private function evaluate(ProjectContext $context): array
    {
        $data = (new LockFile($context->projectRoot))->read();

        $providers = [];

        foreach ($data['providers'] as $name => $info) {
            $version = is_array($info) ? ($info['version'] ?? null) : null;

            $providers[$name] = [
                'lock' => is_string($version) ? $version : null,
                'installed' => $context->installed->version($name),
            ];
        }

        $expected = ExpectedState::build($context, $data['providers']);

        $notices = $expected->warnings;
        $files = [];
        $lackingStubHash = 0;

        foreach ($data['files'] as $destination => $entry) {
            // PHP turns integer-like destinations ("123") into integer array keys.
            $destination = (string) $destination;

            $mapping = $expected->mapping($destination);

            $unmapped = $mapping === null && ($entry['ejected'] ?? false) !== true;

            if ($unmapped && !$context->config->isAllowed($entry['provider'])) {
                $notices[] = ConsoleMessage::COMMAND_PROVIDER_NOT_ALLOWED->getMessage($destination, $entry['provider']);
            }

            $status = $this->evaluateEntry($destination, $entry, $mapping, $expected, $context, $lackingStubHash);

            $files[$destination] = [
                'provider' => $entry['provider'],
                'mode' => $entry['mode'],
                'status' => $status->value,
            ];
        }

        foreach ($expected->all() as $mapping) {
            $destination = $mapping->destination;

            if (!isset($data['files'][$destination])) {
                $files[$destination] = [
                    'provider' => $mapping->providerName,
                    'mode' => $mapping->mode->value,
                    'status' => FileStatus::Pending->value,
                ];
            }
        }

        if ($lackingStubHash > 0) {
            $notices[] = ConsoleMessage::STATUS_STUB_HASH_MISSING->getMessage(
                $lackingStubHash,
                ConsoleMessage::plural(
                    $lackingStubHash,
                    ConsoleMessage::FRAGMENT_ENTRY_LACKS,
                    ConsoleMessage::FRAGMENT_ENTRIES_LACK,
                ),
            );
        }

        return [
            'files' => $files,
            'providers' => $providers,
            'notices' => $notices,
        ];
    }

    /**
     * Returns the state of one lock entry.
     *
     * @param string $destination Destination path as recorded in `scaffold-lock.json`.
     * @param LockEntry $entry Lock entry of the destination.
     * @param FileMapping|null $mapping Expected mapping of the destination, or `null` when no provider ships it.
     * @param ExpectedState $expected Expected mappings, used to tell an orphan from an entry of a failed provider.
     * @param ProjectContext $context Project the entry belongs to.
     * @param int $lackingStubHash Number of `replace`/`preserve` entries without `stub-hash`, incremented in place.
     *
     * @return FileStatus State of the entry; never `pending`.
     */
    private function evaluateEntry(
        string $destination,
        array $entry,
        FileMapping|null $mapping,
        ExpectedState $expected,
        ProjectContext $context,
        int &$lackingStubHash,
    ): FileStatus {
        if (($entry['ejected'] ?? false) === true) {
            return FileStatus::Ejected;
        }

        $validator = new PathValidator();

        try {
            // An unsafe destination is an error before anything else, including the orphan check.
            $validator->validateDestination($destination, $context->projectRoot);

            if ($mapping === null) {
                return $expected->hasFailed($entry['provider']) ? FileStatus::Error : FileStatus::Orphan;
            }

            $validator->validateSource($mapping->source, $mapping->providerPath);
        } catch (RuntimeException) {
            return FileStatus::Error;
        }

        $destPath = PathResolver::destination($context->projectRoot, $destination);

        if (!file_exists($destPath)) {
            return FileStatus::Missing;
        }

        try {
            return $this->compare($destPath, $entry, $mapping, $context->projectRoot, $lackingStubHash);
        } catch (Throwable) {
            return FileStatus::Error;
        }
    }

    /**
     * Writes the status table and the summary line, or the empty-lock message.
     *
     * @param array<array-key, array{provider: string, mode: string, status: string}> $statuses File statuses.
     * @param array<string, int> $counts Counts keyed by state value.
     * @param OutputWriter $out Output sink.
     */
    private function renderTable(array $statuses, array $counts, OutputWriter $out): void
    {
        if ($statuses === []) {
            $out->writeStdout(
                ConsoleMessage::STATUS_NO_FILES->getMessage(),
            );

            return;
        }

        $rows = [];

        foreach ($statuses as $destination => $info) {
            $rows[] = [(string) $destination, $info['provider'], $info['mode'], $info['status']];
        }

        foreach (Table::render(['File', 'Provider', 'Mode', 'Status'], $rows) as $line) {
            $out->writeStdout(
                $line,
            );
        }

        $parts = [];

        foreach (array_filter($counts, static fn(int $number): bool => $number > 0) as $state => $number) {
            $parts[] = ConsoleMessage::FRAGMENT_COUNT->getMessage($number, $state);
        }

        $total = count($statuses);

        $out->writeStdout(
            ConsoleMessage::STATUS_SUMMARY->getMessage(
                $total,
                ConsoleMessage::plural($total, ConsoleMessage::FRAGMENT_FILE, ConsoleMessage::FRAGMENT_FILES),
                implode(', ', $parts),
            ),
        );
    }
}
