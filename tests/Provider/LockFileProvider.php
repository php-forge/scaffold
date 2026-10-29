<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Tests\Scaffold\Lock\LockFileTest;

/**
 * Data provider for {@see LockFileTest} test cases.
 */
final class LockFileProvider
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidContents(): iterable
    {
        yield 'conflict marker inside a line' => [
            "{\"files\": \"a=======b\" invalid}\n",
            Message::LOCK_INVALID_JSON->getMessage('{path}', 'Syntax error'),
        ];
        yield 'Git conflict markers' => [
            "{\n<<<<<<< HEAD\n    \"files\": {}\n=======\n    \"files\": []\n>>>>>>> feature\n}\n",
            Message::LOCK_CONFLICT_MARKERS->getMessage('{path}', 'Syntax error'),
        ];
        yield 'JSON scalar' => [
            '"just a string"',
            Message::LOCK_NOT_OBJECT->getMessage('{path}'),
        ];
        yield 'malformed JSON' => [
            '{ invalid json }',
            Message::LOCK_INVALID_JSON->getMessage('{path}', 'Syntax error'),
        ];
    }
}
