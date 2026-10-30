<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console;

use Symfony\Component\Console\Output\{ConsoleOutputInterface, OutputInterface};

/**
 * Writes service messages to a Symfony Console output in raw mode, so stub content such as `<?php ?>` is never parsed
 * as style tags.
 */
final readonly class SymfonyOutputWriter implements OutputWriter
{
    /**
     * Creates a writer for a Symfony Console output.
     *
     * @param OutputInterface $output Console output that receives the messages and, through its error output when it
     * is a {@see \Symfony\Component\Console\Output\ConsoleOutputInterface}, the standard error messages.
     */
    public function __construct(private OutputInterface $output) {}

    /**
     * Returns whether the console output supports ANSI decoration.
     *
     * @return bool `true` when the main output is decorated, `false` otherwise.
     */
    public function isDecorated(): bool
    {
        return $this->output->isDecorated();
    }

    /**
     * Writes `$message` and a newline to the error output, or to the main output when no error output is available.
     *
     * @param string $message Message without a trailing newline; the writer appends one.
     */
    public function writeStderr(string $message): void
    {
        $sink = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;

        $sink->writeln(
            $message,
            OutputInterface::OUTPUT_RAW,
        );
    }

    /**
     * Writes `$message` and a newline to the main output.
     *
     * @param string $message Message without a trailing newline; the writer appends one.
     */
    public function writeStdout(string $message): void
    {
        $this->output->writeln(
            $message,
            OutputInterface::OUTPUT_RAW,
        );
    }
}
