<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\{ExitCode, Message, OutputWriter};
use PHPForge\Scaffold\Exception\ScaffoldException;
use PHPForge\Scaffold\Manifest\{ManifestExpander, ManifestLoader, ManifestSchema};
use PHPForge\Scaffold\Scaffold\{ExpectedState, FileApplier, ScaffoldRunner, ScaffoldSummary};
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Security\PathValidator;
use RuntimeException;

use function count;

/**
 * Applies the allowed and installed providers to the project from the command line, with the same rules as a
 * Composer `install` or `update`, or previews what applying them would change.
 *
 * @phpstan-import-type LockEntry from LockFile
 */
final class SyncService
{
    /**
     * Synchronizes the project with its providers and ends with a summary line; works with `auto: false` and
     * creates `scaffold-lock.json` when it is missing.
     *
     * @param ProjectContext $context Project to synchronize.
     * @param OutputWriter $out Output sink.
     * @param bool $dryRun Whether to print what applying the providers would change instead of writing anything.
     * @param bool $exitCode Whether a dry run returns `1` when applying would write a file or record a new entry.
     * @param bool $force Whether to overwrite user-modified and adopted `replace` files, like `reapply --force`.
     * @param bool $decorated Whether a dry run colors its diffs.
     *
     * @throws RuntimeException when the lock file cannot be read.
     *
     * @return int `1` when a provider manifest cannot be loaded, a file fails to apply or the lock cannot be written,
     * or with `$dryRun` and `$exitCode` when applying would change something; `0` otherwise.
     */
    public function run(
        ProjectContext $context,
        OutputWriter $out,
        bool $dryRun = false,
        bool $exitCode = false,
        bool $force = false,
        bool $decorated = false,
    ): int {
        if ($context->config->allowedPackages === []) {
            $out->writeStdout(
                Message::SYNC_NO_ALLOWED_PACKAGES->getMessage(),
            );

            return ExitCode::Ok->value;
        }

        $lock = new LockFile($context->projectRoot);

        $data = $lock->read();

        if ($dryRun) {
            return $this->preview($context, $data, $out, $exitCode, $force, $decorated);
        }

        $resolved = $context->providers($data['providers']);

        foreach ($resolved['warnings'] as $warning) {
            $out->writeStderr(
                $warning,
            );
        }

        $summary = new ScaffoldSummary();

        $runner = new ScaffoldRunner(
            new ManifestLoader(new ManifestSchema(), new ManifestExpander()),
            new FileApplier($context->config, new PathValidator(), $out),
            $lock,
            $out,
            $summary,
        );

        try {
            $runner->run($context->config, $resolved['providers'], $context->projectRoot, $force);
        } catch (ScaffoldException) {
            // The runner already wrote the message and counted the error.
        } catch (RuntimeException $e) {
            $out->writeStderr(
                Message::SYNC_ABORTED->getMessage($e->getMessage()),
            );

            $summary->errors++;
        }

        $out->writeStdout(
            $summary->format('sync'),
        );

        return $summary->errors > 0 ? ExitCode::Error->value : ExitCode::Ok->value;
    }

    /**
     * Prints the change applying every expected mapping would make and the orphan list, without writing anything.
     *
     * @param ProjectContext $context Project to preview.
     * @param array{providers: array<string, mixed>, files: array<array-key, LockEntry>} $data Lock data.
     * @param OutputWriter $out Output sink.
     * @param bool $exitCode Whether to return `1` when applying would write a file or record a new entry.
     * @param bool $force Whether to preview user-modified and adopted `replace` files as overwritten.
     * @param bool $decorated Whether to color the diffs.
     *
     * @return int `1` when a manifest or file cannot be read, or with `$exitCode` when something would change.
     */
    private function preview(
        ProjectContext $context,
        array $data,
        OutputWriter $out,
        bool $exitCode,
        bool $force,
        bool $decorated,
    ): int {
        $expected = ExpectedState::build($context, $data['providers']);

        foreach ($expected->warnings as $warning) {
            $out->writeStderr(
                $warning,
            );
        }

        foreach ($context->config->allowedPackages as $name) {
            if (!$context->isInstalled($name, $data['providers'][$name] ?? null)) {
                $out->writeStderr(
                    Message::SCAFFOLD_PROVIDER_NOT_INSTALLED->getMessage($name),
                );
            }
        }

        $errors = count($expected->failedProviders());
        $changes = 0;
        $unchanged = 0;

        $validator = new PathValidator();
        $previewer = new ApplyPreviewer();
        $renderer = new DiffService();

        foreach ($expected->all() as $key => $mapping) {
            // PHP turns integer-like destinations ("123") into integer array keys.
            $destination = (string) $key;

            $entry = $data['files'][$destination] ?? null;

            if (($entry['ejected'] ?? false) === true) {
                continue;
            }

            try {
                $validator->validateDestination($destination, $context->projectRoot);
                $validator->validateSource($mapping->source, $mapping->providerPath);

                $preview = $previewer->preview($mapping, $context->projectRoot, $entry);
            } catch (RuntimeException $e) {
                $out->writeStderr(
                    Message::COMMAND_FILE_ERROR->getMessage($destination, $e->getMessage()),
                );

                $errors++;

                continue;
            }

            if ($force && $preview->userModified) {
                $preview = new ApplyPreview($mapping, $preview->exists, $preview->current, $preview->proposed);
            }

            $renderer->renderPreview($preview, $out, $decorated);

            $writes = $preview->changes() && !$preview->informational && !$preview->userModified;

            if ($writes || $entry === null) {
                $changes++;
            } else {
                $unchanged++;
            }
        }

        $orphans = $expected->orphanReport($data['files']);

        foreach ($orphans as $line) {
            $out->writeStderr($line);
        }

        $out->writeStdout(
            Message::SYNC_DRY_RUN_SUMMARY->getMessage(
                $changes,
                Message::plural($changes, Message::FRAGMENT_FILE, Message::FRAGMENT_FILES),
                $unchanged,
                count($expected->orphans($data['files'])),
                $errors,
                Message::plural($errors, Message::FRAGMENT_ERROR, Message::FRAGMENT_ERRORS),
            ),
        );

        if ($errors > 0) {
            return ExitCode::Error->value;
        }

        return $exitCode && $changes > 0 ? ExitCode::Error->value : ExitCode::Ok->value;
    }
}
