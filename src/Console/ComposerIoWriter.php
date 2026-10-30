<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console;

use Composer\IO\IOInterface;

/**
 * Adapts Composer IO to {@see OutputWriter}, so the Composer-free scaffold core can report through a Composer run.
 */
final readonly class ComposerIoWriter implements OutputWriter
{
    /**
     * Creates a writer for Composer IO.
     *
     * @param IOInterface $io Composer IO that receives the messages.
     */
    public function __construct(private IOInterface $io) {}

    /**
     * Writes `$message` with {@see \Composer\IO\IOInterface::writeError()}.
     *
     * @param string $message Message without a trailing newline; the writer appends one.
     */
    public function writeStderr(string $message): void
    {
        $this->io->writeError(
            $message,
        );
    }

    /**
     * Writes `$message` with {@see \Composer\IO\IOInterface::write()}.
     *
     * @param string $message Message without a trailing newline; the writer appends one.
     */
    public function writeStdout(string $message): void
    {
        $this->io->write(
            $message,
        );
    }
}
