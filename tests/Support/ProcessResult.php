<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

/**
 * Represents the exit code and the captured output streams of a finished subprocess.
 */
final readonly class ProcessResult
{
    /**
     * Creates a process result.
     *
     * @param int $exitCode Exit code of the process.
     * @param string $stdout Captured standard output.
     * @param string $stderr Captured standard error.
     */
    public function __construct(public int $exitCode, public string $stdout, public string $stderr) {}

    /**
     * Returns both streams, standard output first, for failure messages.
     */
    public function output(): string
    {
        return "--- stdout\n{$this->stdout}\n--- stderr\n{$this->stderr}";
    }

    /**
     * Returns the exit code as a string, followed by both streams when it differs from `$expected`, so a failed
     * comparison with `$expected` shows the output of the process.
     *
     * @param int $expected Expected exit code.
     */
    public function status(int $expected = 0): string
    {
        return $this->exitCode === $expected ? (string) $this->exitCode : "{$this->exitCode}\n{$this->output()}";
    }
}
