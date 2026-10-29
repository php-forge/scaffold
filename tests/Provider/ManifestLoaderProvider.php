<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use Exception;
use JsonException;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Tests\Manifest\ManifestLoaderTest;
use RuntimeException;

/**
 * Data provider for {@see ManifestLoaderTest} test cases.
 */
final class ManifestLoaderProvider
{
    /**
     * @return iterable<string, array{string, string|null, class-string<Exception>, Message|string}>
     */
    public static function decodeFailures(): iterable
    {
        yield 'composer.json invalid JSON' => [
            'composer.json', '{broken',
            JsonException::class,
            'Syntax error',
        ];
        yield 'composer.json missing' => [
            'composer.json',
            null,
            RuntimeException::class,
            Message::MANIFEST_PROVIDER_FILE_NOT_FOUND,
        ];
        yield 'composer.json not an object' => [
            'composer.json',
            '3',
            RuntimeException::class,
            Message::MANIFEST_PROVIDER_FILE_NOT_OBJECT,
        ];
        yield 'manifest file invalid JSON' => [
            'manifest file',
            '{ not valid json ',
            JsonException::class,
            'Syntax error',
        ];
        yield 'manifest file missing' => [
            'manifest file',
            null,
            RuntimeException::class,
            Message::MANIFEST_PROVIDER_FILE_NOT_FOUND,
        ];
        yield 'manifest file not an object' => [
            'manifest file',
            '"just-a-string"',
            RuntimeException::class,
            Message::MANIFEST_PROVIDER_FILE_NOT_OBJECT,
        ];
    }

    /**
     * @return iterable<string, array{mixed, Message}>
     */
    public static function invalidReferences(): iterable
    {
        yield 'absolute path' => ['/etc/malicious.json', Message::MANIFEST_REFERENCE_NOT_RELATIVE];
        yield 'empty string' => ['', Message::MANIFEST_REFERENCE_EMPTY];
        yield 'not a string' => [42, Message::MANIFEST_REFERENCE_EMPTY];
        yield 'traversal' => ['../escape.json', Message::MANIFEST_REFERENCE_NOT_RELATIVE];
    }
}
