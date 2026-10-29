<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console;

use Composer\IO\BufferIO;
use PHPForge\Scaffold\Console\ComposerIoWriter;
use Symfony\Component\Console\Output\StreamOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function fopen;
use function rewind;
use function stream_get_contents;

/**
 * Unit tests for {@see ComposerIoWriter} routing messages to Composer's standard and error outputs.
 */
#[Test]
#[Covers(ComposerIoWriter::class)]
final class ComposerIoWriterTest
{
    /**
     * Ensures stdout messages reach `write()` and stderr messages reach `writeError()`.
     */
    public function routesStdoutToWriteAndStderrToWriteError(): void
    {
        $errors = fopen('php://memory', 'w+');

        Assert::true(
            $errors !== false,
            'Memory stream must open.',
        );

        $io = new class ($errors) extends BufferIO {
            /**
             * Captures the error output in `$errors`.
             *
             * @param resource $errors Stream that receives the error output.
             */
            public function __construct(private $errors)
            {
                parent::__construct();
            }

            /**
             * Writes `$messages` to the captured error stream.
             */
            public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL): void
            {
                (new StreamOutput($this->errors))->write($messages, $newline);
            }
        };

        $writer = new ComposerIoWriter($io);

        $writer->writeStdout('[scaffold] out');
        $writer->writeStderr('[scaffold] err');

        rewind($errors);

        Assert::same(
            $io->getOutput(),
            '[scaffold] out' . PHP_EOL,
            "Stdout must reach 'write()'.",
        );
        Assert::same(
            stream_get_contents($errors),
            '[scaffold] err' . PHP_EOL,
            "Stderr must reach 'writeError()'.",
        );
    }
}
