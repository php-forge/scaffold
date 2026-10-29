<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Manifest\FileMode;
use PHPForge\Scaffold\Scaffold\Modes\{
    AppendMode,
    ApplyOutcome,
    ModeInterface,
    PrependMode,
    PreserveMode,
    ReplaceMode,
};
use PHPForge\Scaffold\Tests\Scaffold\Modes\{ModeContractTest, PrependModeTest, PreserveModeTest, ReplaceModeTest};

use function hash;

/**
 * Data provider for {@see ModeContractTest}, {@see PrependModeTest}, {@see PreserveModeTest} and
 * {@see ReplaceModeTest} test cases.
 */
final class ModeProvider
{
    /**
     * Destination content shared by the replace decisions.
     */
    private const string EXISTING = 'original scaffold content';
    /**
     * Stub content shared by the replace decisions.
     */
    private const string STUB = 'updated stub content';

    /**
     * @return iterable<string, array{ModeInterface, FileMode}>
     */
    public static function copyModes(): iterable
    {
        yield 'preserve' => [new PreserveMode(), FileMode::Preserve];
        yield 'replace' => [new ReplaceMode(), FileMode::Replace];
    }

    /**
     * @return iterable<string, array{ModeInterface, FileMode, string|null}>
     */
    public static function missingDestinations(): iterable
    {
        foreach (self::modes() as $name => [$mode, $fileMode]) {
            yield "{$name} without lock hash" => [$mode, $fileMode, null];
            yield "{$name} with stale lock hash" => [$mode, $fileMode, 'sha256:stale'];
        }
    }

    /**
     * @return iterable<string, array{ModeInterface, FileMode, string}>
     */
    public static function missingSources(): iterable
    {
        $read = Message::FILESYSTEM_FAILED->getMessage(
            Message::MODE_SOURCE_UNREADABLE->getMessage('{source}'),
            'file_get_contents({source}): Failed to open stream: No such file or directory',
        );
        $copy = Message::FILESYSTEM_FAILED->getMessage(
            Message::MODE_COPY_FAILED->getMessage('{source}', '{destination}'),
            'copy({source}): Failed to open stream: No such file or directory',
        );

        yield 'append' => [new AppendMode(), FileMode::Append, $read];
        yield 'prepend' => [new PrependMode(), FileMode::Prepend, $read];
        yield 'preserve' => [new PreserveMode(), FileMode::Preserve, $copy];
        yield 'replace' => [new ReplaceMode(), FileMode::Replace, $copy];
    }

    /**
     * @return iterable<string, array{ModeInterface, FileMode}>
     */
    public static function modes(): iterable
    {
        yield 'append' => [new AppendMode(), FileMode::Append];
        yield 'prepend' => [new PrependMode(), FileMode::Prepend];
        yield 'preserve' => [new PreserveMode(), FileMode::Preserve];
        yield 'replace' => [new ReplaceMode(), FileMode::Replace];
    }

    /**
     * @return iterable<string, array{string, string|null, string|null}>
     */
    public static function prependBlocks(): iterable
    {
        yield 'missing destination' => [
            "a\n",
            null,
            "a\n",
        ];
        yield 'empty destination' => [
            "# header\r\n",
            '',
            "# header\r\n",
        ];
        yield 'block before existing content' => [
            'PREPENDED',
            'EXISTING',
            "PREPENDED\nEXISTING",
        ];
        yield 'stub without trailing newline' => [
            'prepended ',
            'existing',
            "prepended \nexisting",
        ];
        yield 'destination line ending' => [
            "a\nb\n",
            "x\r\n",
            "a\r\nb\r\nx\r\n",
        ];
        yield 'empty stub' => [
            '',
            "x\n",
            null,
        ];
        yield 'empty stub with empty destination' => [
            '',
            '',
            null,
        ];
        yield 'block at the start' => [
            "# header\n",
            "# header\nbody\n",
            null,
        ];
        yield 'block at the end' => [
            "# footer\n",
            "body\n# footer\n",
            null,
        ];
        yield 'block moved by the user' => [
            "# header\n# line 2\n",
            "intro\n# header\n# line 2\nbody",
            null,
        ];
        yield 'block present after line ending normalisation' => [
            "# header\n# line 2\n",
            "# header\r\n# line 2\r\nbody\r\n",
            null,
        ];
    }

    /**
     * @return iterable<string, array{string|null, string, string, ApplyOutcome, string, string, string|null}>
     */
    public static function replaceDecisions(): iterable
    {
        yield 'tracked and unmodified' => [
            self::hashOf(self::EXISTING),
            self::EXISTING,
            self::STUB,
            ApplyOutcome::Written,
            self::STUB,
            self::hashOf(self::STUB),
            null,
        ];
        yield 'tracked and modified by the user' => [
            self::hashOf('content recorded at scaffold time'),
            self::EXISTING,
            self::STUB,
            ApplyOutcome::Skipped,
            self::EXISTING,
            '',
            ConsoleMessage::SCAFFOLD_USER_MODIFIED_SKIPPED->getMessage('output.txt'),
        ];
        yield 'untracked with different content' => [
            null,
            self::EXISTING,
            self::STUB,
            ApplyOutcome::Written,
            self::STUB,
            self::hashOf(self::STUB),
            ConsoleMessage::SCAFFOLD_UNTRACKED_OVERWRITTEN->getMessage('output.txt'),
        ];
        yield 'untracked with identical content' => [
            null,
            self::STUB,
            self::STUB,
            ApplyOutcome::Written,
            self::STUB,
            self::hashOf(self::STUB),
            null,
        ];
    }

    /**
     * @return iterable<string, array{ModeInterface, FileMode, string}>
     */
    public static function unreadableDestinations(): iterable
    {
        $read = Message::MODE_DESTINATION_UNREADABLE->getMessage('{destination}')
            . ' Reason: file_get_contents(';
        $hash = Message::LOCK_HASH_FAILED->getMessage('{destination}') . ' Reason: hash_file(';

        yield 'append' => [new AppendMode(), FileMode::Append, $read];
        yield 'prepend' => [new PrependMode(), FileMode::Prepend, $read];
        yield 'preserve' => [new PreserveMode(), FileMode::Preserve, $hash];
        yield 'replace' => [new ReplaceMode(), FileMode::Replace, $hash];
    }

    /**
     * @return iterable<string, array{ModeInterface, FileMode, string}>
     */
    public static function unwritableDestinations(): iterable
    {
        $write = Message::FILESYSTEM_FAILED->getMessage(
            Message::MODE_WRITE_FAILED->getMessage('{destination}'),
            'file_put_contents({destination}): Failed to open stream: No such file or directory',
        );
        $copy = Message::FILESYSTEM_FAILED->getMessage(
            Message::MODE_COPY_FAILED->getMessage('{source}', '{destination}'),
            'copy({destination}): Failed to open stream: No such file or directory',
        );

        yield 'append' => [new AppendMode(), FileMode::Append, $write];
        yield 'prepend' => [new PrependMode(), FileMode::Prepend, $write];
        yield 'preserve' => [new PreserveMode(), FileMode::Preserve, $copy];
        yield 'replace' => [new ReplaceMode(), FileMode::Replace, $copy];
    }

    /**
     * Returns the lock hash of `$content` in the `sha256:<hex>` format of {@see \PHPForge\Scaffold\Scaffold\Lock\Hasher}.
     *
     * @param string $content File content.
     *
     * @return string Prefixed SHA-256 hash.
     */
    private static function hashOf(string $content): string
    {
        return 'sha256:' . hash('sha256', $content);
    }
}
