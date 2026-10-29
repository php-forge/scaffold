<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use PHPForge\Scaffold\Manifest\FileMode;
use PHPForge\Scaffold\Merge\MergeStrategyKind;
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Tests\Services\ApplyPreviewerTest;

use function hash;

/**
 * Data provider for {@see ApplyPreviewerTest} test cases.
 *
 * @phpstan-import-type LockEntry from LockFile
 */
final class ApplyPreviewerProvider
{
    /**
     * @return iterable<string, array{bool, string, string, bool, bool}>
     */
    public static function lineEndings(): iterable
    {
        yield 'CRLF against LF' => [true, "a\r\nb\r\n", "a\nb\n", true, true];
        yield 'missing destination' => [false, '', "a\n", true, false];
        yield 'identical content' => [true, "a\n", "a\n", false, false];
    }

    /**
     * @return iterable<
     *   string,
     *   array{string, FileMode, MergeStrategyKind|null, string, string|null, array<string, bool|string>}
     * >
     */
    public static function modes(): iterable
    {
        yield 'append with the recorded strategy' => [
            'list.cfg',
            FileMode::Append,
            MergeStrategyKind::ListFile,
            "a\nb\n",
            "a\nmine\n",
            self::preview(true, "a\nmine\n", "a\nmine\n\nb\n", true, false),
        ];
        yield 'append already satisfied' => [
            '.gitignore',
            FileMode::Append,
            null,
            "a\n",
            "mine\na\n",
            self::preview(true, "mine\na\n", "mine\na\n", false, false),
        ];
        yield 'replace of a missing destination' => [
            'new.txt',
            FileMode::Replace,
            null,
            "n\n",
            null,
            self::preview(false, '', "n\n", true, false),
        ];
        yield 'prepend with the destination line ending' => [
            'h.php',
            FileMode::Prepend,
            null,
            "<?php\n",
            "echo 1;\r\n",
            self::preview(true, "echo 1;\r\n", "<?php\r\necho 1;\r\n", true, false),
        ];
        yield 'preserve of an existing destination' => [
            'ecs.php',
            FileMode::Preserve,
            null,
            "stub\n",
            "mine\n",
            self::preview(true, "mine\n", "stub\n", true, true),
        ];
    }

    /**
     * Provides lock entries of a `replace` destination holding `mine\n` with the expected `userModified` flag.
     *
     * @return iterable<string, array{LockEntry|null, bool}>
     */
    public static function userModified(): iterable
    {
        $entry = [
            'hash' => 'sha256:other',
            'provider' => 'p/a',
            'source' => 'stubs/a.txt',
            'mode' => 'replace',
        ];
        $matching = [
            ...$entry,
            'hash' => 'sha256:' . hash('sha256', "mine\n"),
        ];

        yield 'adopted with a matching hash' => [[...$matching, 'adopted' => true], true];
        yield 'adopted' => [['adopted' => true, ...$entry], true];
        yield 'hash differs' => [$entry, true];
        yield 'hash matches' => [$matching, false];
        yield 'untracked' => [null, false];
    }

    /**
     * Returns the expected preview values keyed by property.
     *
     * @return array<string, bool|string>
     */
    private static function preview(
        bool $exists,
        string $current,
        string $proposed,
        bool $changes,
        bool $informational,
    ): array {
        return [
            'exists' => $exists,
            'current' => $current,
            'proposed' => $proposed,
            'changes' => $changes,
            'informational' => $informational,
        ];
    }
}
