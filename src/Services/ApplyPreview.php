<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

use LogicException;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Manifest\FileMapping;

use function preg_last_error_msg;
use function preg_replace;

/**
 * Represents the content a destination has now and the content it would have after applying its provider mapping.
 */
final readonly class ApplyPreview
{
    /**
     * @param FileMapping $mapping Mapping that would be applied.
     * @param bool $exists Whether the destination exists on disk.
     * @param string $current Destination content on disk; an empty string when the destination does not exist.
     * @param string $proposed Destination content after applying the mapping.
     * @param bool $userModified Whether the destination is a `replace` file that scaffold skips because it changed
     * since the last apply or was adopted.
     * @param bool $informational Whether the destination is an existing `preserve` file that only `reapply --force`
     * overwrites.
     */
    public function __construct(
        public FileMapping $mapping,
        public bool $exists,
        public string $current,
        public string $proposed,
        public bool $userModified = false,
        public bool $informational = false,
    ) {}

    /**
     * Returns whether applying the mapping would create the destination or change its bytes.
     *
     * @return bool `true` when the destination is missing or its content differs from the proposed one, `false`
     * otherwise.
     */
    public function changes(): bool
    {
        return !$this->exists || $this->current !== $this->proposed;
    }

    /**
     * Returns whether the current and proposed contents differ only in their line endings.
     *
     * @return bool `true` when the destination exists and its content equals the proposed one after normalising line
     * endings, `false` otherwise.
     */
    public function differsOnlyInLineEndings(): bool
    {
        return $this->exists
            && $this->current !== $this->proposed
            && self::normaliseLineEndings($this->current) === self::normaliseLineEndings($this->proposed);
    }

    /**
     * Normalises `\r\n` and `\r` line endings to `\n`.
     *
     * @param string $text Text to normalise.
     *
     * @return string Text with `\n` line endings only.
     */
    private static function normaliseLineEndings(string $text): string
    {
        return preg_replace('/\r\n|\r/', "\n", $text)
            ?? throw new LogicException(Message::REGEX_FAILED->getMessage(preg_last_error_msg()));
    }
}
