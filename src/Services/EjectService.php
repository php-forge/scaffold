<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

use PHPForge\Scaffold\Console\{ExitCode, Message, OutputWriter};
use PHPForge\Scaffold\Scaffold\Lock\LockFile;

/**
 * Marks a file entry as ejected in `scaffold-lock.json` so scaffold stops writing it; the file stays on disk.
 */
final class EjectService
{
    /**
     * Marks the entry of `$file` as ejected, or only previews the change when `$confirmed` is `false`.
     *
     * @param string $projectRoot Absolute path to the project root.
     * @param OutputWriter $out Output sink.
     * @param string $file Destination path as recorded in `scaffold-lock.json`.
     * @param bool $confirmed Whether to write the ejection; `false` only previews it.
     *
     * @throws \RuntimeException when the lock file cannot be read or written.
     *
     * @return int `0` on success, including previews and entries already ejected; `1` when the entry is not tracked.
     */
    public function run(string $projectRoot, OutputWriter $out, string $file, bool $confirmed = false): int
    {
        $lock = new LockFile($projectRoot);

        $data = $lock->read();

        if (!isset($data['files'][$file])) {
            $out->writeStderr(
                Message::COMMAND_FILE_NOT_TRACKED->getMessage($file),
            );

            return ExitCode::Error->value;
        }

        if (($data['files'][$file]['ejected'] ?? false) === true) {
            $out->writeStdout(
                Message::EJECT_ALREADY_EJECTED->getMessage($file),
            );

            return ExitCode::Ok->value;
        }

        if (!$confirmed) {
            $out->writeStdout(
                Message::EJECT_CONFIRMATION_REQUIRED->getMessage($file),
            );

            return ExitCode::Ok->value;
        }

        $data['files'][$file]['ejected'] = true;

        $lock->write($data);

        $out->writeStdout(
            Message::EJECT_EJECTED->getMessage($file),
        );

        return ExitCode::Ok->value;
    }
}
