<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Tests\Scaffold\FileApplierTest;

/**
 * Data provider for {@see FileApplierTest} test cases.
 */
final class FileApplierProvider
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function rejectedMappings(): iterable
    {
        yield 'provider not allowed' => [
            'output.txt',
            'stubs/output.txt',
            'php-forge/unauthorized',
            Message::PACKAGE_NOT_ALLOWED->getMessage('php-forge/unauthorized'),
        ];
        yield 'destination with traversal' => [
            '../../../etc/passwd',
            'stubs/output.txt',
            'p/a',
            Message::PATH_TRAVERSAL->getMessage('destination', '../../../etc/passwd'),
        ];
        yield 'source with traversal' => [
            'output.txt',
            '../../../etc/shadow',
            'p/a',
            Message::PATH_TRAVERSAL->getMessage('source', '../../../etc/shadow'),
        ];
    }
}
