<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\Console\SymfonyOutputWriterTest;

/**
 * Data provider for {@see SymfonyOutputWriterTest} test cases.
 */
final class SymfonyOutputWriterProvider
{
    /**
     * @return iterable<string, array{string, bool, bool, string}>
     */
    public static function writes(): iterable
    {
        yield 'stderr falls back to the primary output' => ['writeStderr', false, false, 'plain <output> fallback'];
        yield 'stderr goes to the console error output' => ['writeStderr', true, true, 'error <bold>line</bold>'];
        yield 'stdout keeps angle-bracket tokens' => ['writeStdout', false, false, '<?php echo $user; ?>'];
        yield 'stdout keeps style tags when decorated' => ['writeStdout', false, true, '<info>hello</info>'];
    }
}
