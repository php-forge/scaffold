<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console;

/**
 * Writes service messages to standard output or standard error, appending one newline per message.
 */
interface OutputWriter
{
    /**
     * Writes `$message` to the standard error stream followed by a trailing newline.
     *
     * @param string $message Message without a trailing newline; the writer appends one.
     */
    public function writeStderr(string $message): void;

    /**
     * Writes `$message` to the standard output stream followed by a trailing newline.
     *
     * @param string $message Message without a trailing newline; the writer appends one.
     */
    public function writeStdout(string $message): void;
}
