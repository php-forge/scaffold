<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Tests\ComposerFreeRuntimeTest;

/**
 * Data provider for {@see ComposerFreeRuntimeTest} test cases.
 */
final class ComposerFreeRuntimeProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function adapterOnlyReferences(): iterable
    {
        yield 'Composer adapter classes' => ['/\b(?:Scaffolder|EventSubscriber|ComposerIoWriter)\b(?!::)/'];
        yield 'Composer classes other than InstalledVersions' => ['/Composer\\\\(?!InstalledVersions\b)[A-Za-z]+/'];
    }
}
