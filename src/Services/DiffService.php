<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

use PHPForge\Diff\UnifiedDiffRenderer;
use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\{DiffColorizer, ExitCode, Message, OutputWriter};
use PHPForge\Scaffold\Scaffold\ExpectedState;
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Security\PathValidator;
use RuntimeException;

/**
 * Renders Git-compatible unified diffs of what applying the provider would change in tracked files.
 */
final class DiffService
{
    /**
     * Writes the header and diff of one preview, `no changes`, or the line-ending notice.
     *
     * @param ApplyPreview $preview Preview of the destination to render.
     * @param OutputWriter $out Output sink.
     * @param bool $decorated Whether to wrap the diff lines in ANSI color escape codes.
     *
     * @return bool `true` when applying the mapping would create or change the destination, `false` otherwise.
     */
    public function renderPreview(ApplyPreview $preview, OutputWriter $out, bool $decorated = false): bool
    {
        $file = $preview->mapping->destination;

        if (!$preview->changes()) {
            $out->writeStdout(
                Message::DIFF_NO_CHANGES->getMessage($file),
            );

            return false;
        }

        if ($preview->differsOnlyInLineEndings()) {
            $out->writeStdout(
                Message::DIFF_LINE_ENDINGS_ONLY->getMessage($file),
            );

            return true;
        }

        $suffix = match (true) {
            $preview->userModified => Message::FRAGMENT_USER_MODIFIED_PREVIEW->getMessage(),
            $preview->informational => Message::FRAGMENT_PRESERVE_PREVIEW->getMessage(),
            default => '',
        };

        $out->writeStdout(
            Message::DIFF_PREVIEW_HEADER->getMessage(
                $file,
                $preview->mapping->mode->value,
                $preview->mapping->providerName,
                $suffix,
            ),
        );

        $diff = (new UnifiedDiffRenderer())
            ->render($preview->current, $preview->proposed, 'a/' . $file, 'b/' . $file);

        if ($diff === '') {
            $out->writeStdout(
                Message::DIFF_CREATED_EMPTY->getMessage($file),
            );

            return true;
        }

        $out->writeStdout(
            $decorated ? (new DiffColorizer())->colorize($diff) : $diff,
        );

        return true;
    }

    /**
     * Renders what applying the provider would change in `$file`, or in every tracked, non-ejected file when `$file`
     * is `null`, against the current disk content; orphans are reported, not diffed.
     *
     * @param ProjectContext $context Project to inspect.
     * @param OutputWriter $out Output sink.
     * @param string|null $file Destination path as recorded in `scaffold-lock.json`, or `null` for every file.
     * @param bool $decorated Whether to wrap the diff lines in ANSI color escape codes.
     * @param bool $exitCode Whether to return `1` when applying the provider would change any file.
     *
     * @throws RuntimeException when the lock file cannot be read.
     *
     * @return int `1` when the file is not tracked, an entry is unsafe, its provider is not allowed, or a file cannot
     * be read, or with `$exitCode` when any file would change; `0` otherwise.
     */
    public function run(
        ProjectContext $context,
        OutputWriter $out,
        string|null $file = null,
        bool $decorated = false,
        bool $exitCode = false,
    ): int {
        $data = (new LockFile($context->projectRoot))->read();

        if ($file !== null && !isset($data['files'][$file])) {
            $out->writeStderr(
                Message::COMMAND_FILE_NOT_TRACKED->getMessage($file),
            );

            return ExitCode::Error->value;
        }

        $expected = ExpectedState::build($context, $data['providers']);

        foreach ($expected->warnings as $warning) {
            $out->writeStderr($warning);
        }

        $validator = new PathValidator();
        $previewer = new ApplyPreviewer();

        $errors = 0;
        $changes = 0;

        foreach ($data['files'] as $destination => $entry) {
            // PHP turns integer-like destinations ("123") into integer array keys.
            $destination = (string) $destination;

            if ($file !== null && $destination !== $file) {
                continue;
            }

            if (($entry['ejected'] ?? false) === true) {
                if ($file !== null) {
                    $out->writeStdout(
                        Message::DIFF_EJECTED->getMessage($file),
                    );
                }

                continue;
            }

            try {
                $validator->validateDestination($destination, $context->projectRoot);

                $mapping = $expected->mapping($destination);

                if ($mapping === null) {
                    if ($this->reportUnmapped($destination, $entry['provider'], $context, $expected, $out)) {
                        $errors++;
                    }

                    continue;
                }

                $validator->validateSource($mapping->source, $mapping->providerPath);
            } catch (RuntimeException $e) {
                $out->writeStderr(
                    Message::COMMAND_UNSAFE_ENTRY->getMessage($destination, $e->getMessage()),
                );

                $errors++;

                continue;
            }

            try {
                $preview = $previewer->preview($mapping, $context->projectRoot, $entry);
            } catch (RuntimeException $e) {
                $out->writeStderr(
                    Message::COMMAND_FILE_ERROR->getMessage($destination, $e->getMessage()),
                );

                $errors++;

                continue;
            }

            if ($this->renderPreview($preview, $out, $decorated)) {
                $changes++;
            }
        }

        if ($errors > 0) {
            return ExitCode::Error->value;
        }

        return $exitCode && $changes > 0 ? ExitCode::Error->value : ExitCode::Ok->value;
    }

    /**
     * Reports a tracked file that no allowed and installed provider ships.
     *
     * @param string $destination Destination path as recorded in `scaffold-lock.json`.
     * @param string $provider Provider recorded in the lock entry.
     * @param ProjectContext $context Project the entry belongs to.
     * @param ExpectedState $expected Expected mappings, used to detect a provider whose manifest failed to load.
     * @param OutputWriter $out Output sink.
     *
     * @return bool `true` when the provider is not allowed or its manifest could not be loaded, `false` when the entry
     * is an orphan.
     */
    private function reportUnmapped(
        string $destination,
        string $provider,
        ProjectContext $context,
        ExpectedState $expected,
        OutputWriter $out,
    ): bool {
        if (!$context->config->isAllowed($provider)) {
            $out->writeStderr(
                Message::COMMAND_PROVIDER_NOT_ALLOWED->getMessage($destination, $provider),
            );

            return true;
        }

        if ($expected->hasFailed($provider)) {
            $out->writeStderr(
                Message::DIFF_MANIFEST_NOT_LOADED->getMessage($destination, $provider),
            );

            return true;
        }

        $out->writeStdout(
            Message::DIFF_ORPHAN->getMessage($destination),
        );

        return false;
    }
}
