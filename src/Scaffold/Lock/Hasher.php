<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold\Lock;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use RuntimeException;

/**
 * Computes the `sha256:<hex>` hashes that `scaffold-lock.json` records for scaffolded files.
 */
final class Hasher
{
    /**
     * Returns a `sha256:<hex>` hash of the file at the given path.
     *
     * @param string $absolutePath Absolute path to the file.
     *
     * @throws RuntimeException when the file cannot be read.
     *
     * @return string Hash in the format `sha256:<hex>`.
     */
    public static function hash(string $absolutePath): string
    {
        return 'sha256:' . Filesystem::hash($absolutePath, Message::LOCK_HASH_FAILED->getMessage($absolutePath));
    }
}
