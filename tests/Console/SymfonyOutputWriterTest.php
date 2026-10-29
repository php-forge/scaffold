<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console;

use PHPForge\Scaffold\Console\SymfonyOutputWriter;
use PHPForge\Scaffold\Tests\Provider\SymfonyOutputWriterProvider;
use Symfony\Component\Console\Output\{BufferedOutput, ConsoleOutput, OutputInterface};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see SymfonyOutputWriter} verifying that stdout and stderr writes bypass Symfony's output formatter,
 * so pre-formatted service messages (for example, diffs that contain angle-bracket tokens) land verbatim.
 *
 * {@see SymfonyOutputWriterProvider} for test case data providers.
 */
#[Test]
#[Covers(SymfonyOutputWriter::class)]
final class SymfonyOutputWriterTest
{
    /**
     * Ensures the message lands verbatim with one trailing newline on the stream of the write method.
     *
     * @param string $method Write method under test, `writeStdout` or `writeStderr`.
     * @param bool $console Whether the writer wraps a console output whose error output is the buffer.
     * @param bool $decorated Whether the buffer is decorated.
     */
    #[DataProvider(SymfonyOutputWriterProvider::class . '::writes')]
    public function writesTheMessageVerbatim(string $method, bool $console, bool $decorated, string $message): void
    {
        $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $decorated);

        $output = $buffer;

        if ($console) {
            $output = new ConsoleOutput();

            $output->setErrorOutput($buffer);
        }

        $writer = new SymfonyOutputWriter($output);

        match ($method) {
            'writeStderr' => $writer->writeStderr($message),
            default => $writer->writeStdout($message),
        };

        Assert::same(
            $buffer->fetch(),
            $message . PHP_EOL,
            "Message must land verbatim on the '{$method}' stream.",
        );
    }
}
