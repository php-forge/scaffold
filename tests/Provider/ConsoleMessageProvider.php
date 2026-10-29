<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use LogicException;
use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Tests\Console\MessageTest;

use function array_keys;
use function implode;

/**
 * Data provider for {@see MessageTest} test cases.
 */
final class ConsoleMessageProvider
{
    /**
     * Returns every console message case with its arguments and pinned literal.
     *
     * @throws LogicException when a case has no pinned literal, or a literal has no case.
     *
     * @return iterable<string, array{Message, list<int|string>, string}>
     */
    public static function cases(): iterable
    {
        $literals = self::literals();

        foreach (Message::cases() as $case) {
            yield $case->name => [
                $case,
                ...$literals[$case->name] ?? throw new LogicException("Case '{$case->name}' has no pinned literal."),
            ];

            unset($literals[$case->name]);
        }

        if ($literals !== []) {
            throw new LogicException('Literals without a case: ' . implode(', ', array_keys($literals)) . '.');
        }
    }

    /**
     * @return array<string, array{list<int|string>, string}>
     */
    private static function literals(): array
    {
        return [
            'COMMAND_CWD_UNRESOLVABLE' => [
                [],
                '[scaffold] Unable to determine current working directory.',
            ],
            'COMMAND_FILE_ERROR' => [
                ['a.txt', 'Stub not found: "/vendor/p/a/stubs/a.txt".'],
                '[scaffold] "a.txt": Stub not found: "/vendor/p/a/stubs/a.txt".',
            ],
            'COMMAND_FILE_NOT_TRACKED' => [
                ['a.txt'],
                '[scaffold] "a.txt" is not tracked in scaffold-lock.json.',
            ],
            'COMMAND_MANIFEST_NOT_FOUND' => [
                ['composer.json', '/project'],
                '[scaffold] No composer.json found in "/project" or any parent directory.',
            ],
            'COMMAND_PROVIDER_NOT_ALLOWED' => [
                ['a.txt', 'p/a'],
                '[scaffold] "a.txt": provider "p/a" is not in extra.scaffold.allowed-packages; skipped.',
            ],
            'COMMAND_UNSAFE_ENTRY' => [
                ['../a.txt', 'Destination path "../a.txt" contains a traversal segment.'],
                '[scaffold] Unsafe lock entry for "../a.txt": Destination path "../a.txt" contains a traversal '
                . 'segment.',
            ],
            'COMMAND_WORKING_DIR_NOT_FOUND' => [
                ['/missing'],
                '[scaffold] Working directory "/missing" does not exist.',
            ],
            'DIFF_CREATED_EMPTY' => [
                ['a.txt'],
                '[scaffold] a.txt: would be created empty.',
            ],
            'DIFF_EJECTED' => [
                ['a.txt'],
                '[scaffold] a.txt: ejected; scaffold does not manage it (see reapply --force).',
            ],
            'DIFF_LINE_ENDINGS_ONLY' => [
                ['a.txt'],
                '[scaffold] a.txt: differs only in line endings.',
            ],
            'DIFF_MANIFEST_NOT_LOADED' => [
                ['a.txt', 'p/a'],
                '[scaffold] "a.txt": the manifest of provider "p/a" could not be loaded; skipped.',
            ],
            'DIFF_NO_CHANGES' => [
                ['a.txt'],
                '[scaffold] a.txt: no changes.',
            ],
            'DIFF_ORPHAN' => [
                ['a.txt'],
                '[scaffold] a.txt: orphan; no allowed provider ships it anymore.',
            ],
            'DIFF_PREVIEW_HEADER' => [
                ['a.txt', 'replace', 'p/a', ''],
                '[scaffold] a.txt (replace, p/a): changes applying the provider would make',
            ],
            'DIFF_TARGET_AMBIGUOUS' => [
                [],
                '[scaffold] Pass either a file or --all.',
            ],
            'EJECT_ALREADY_EJECTED' => [
                ['a.txt'],
                '[scaffold] "a.txt" is already ejected.',
            ],
            'EJECT_CONFIRMATION_REQUIRED' => [
                ['a.txt'],
                '[scaffold] Would eject "a.txt": it will not be managed until reapplied with --force. Run with --yes '
                . 'to confirm.',
            ],
            'EJECT_EJECTED' => [
                ['a.txt'],
                '[scaffold] Ejected "a.txt": it will not be managed until reapplied with --force. The file was not '
                . 'deleted from disk.',
            ],
            'FRAGMENT_COMMAND' => [
                ['sync'],
                'sync: ',
            ],
            'FRAGMENT_CONFLICT' => [
                [],
                'conflict',
            ],
            'FRAGMENT_CONFLICT_FILE' => [
                [],
                'file has',
            ],
            'FRAGMENT_CONFLICT_FILES' => [
                [],
                'files have',
            ],
            'FRAGMENT_COUNT' => [
                [2, 'synced'],
                '2 synced',
            ],
            'FRAGMENT_COUNT_UNCHANGED' => [
                [8],
                '8 unchanged',
            ],
            'FRAGMENT_COUNT_WRITTEN' => [
                [3, 'files'],
                '3 files written',
            ],
            'FRAGMENT_DRY_RUN' => [
                [],
                ' --dry-run',
            ],
            'FRAGMENT_ENTRIES_LACK' => [
                [],
                'entries lack',
            ],
            'FRAGMENT_ENTRY_LACKS' => [
                [],
                'entry lacks',
            ],
            'FRAGMENT_ERROR' => [
                [],
                'error',
            ],
            'FRAGMENT_ERRORS' => [
                [],
                'errors',
            ],
            'FRAGMENT_FILE' => [
                [],
                'file',
            ],
            'FRAGMENT_FILES' => [
                [],
                'files',
            ],
            'FRAGMENT_ORPHAN' => [
                [],
                'orphan',
            ],
            'FRAGMENT_OUTDATED' => [
                [],
                'outdated',
            ],
            'FRAGMENT_PARENTHESIZED' => [
                ['modified'],
                ' (modified)',
            ],
            'FRAGMENT_PRESERVE_PREVIEW' => [
                [],
                ' (preserve: applied only with reapply --force)',
            ],
            'FRAGMENT_UNKNOWN_PROVIDER' => [
                [],
                '?',
            ],
            'FRAGMENT_USER_MODIFIED_PREVIEW' => [
                [],
                ' (user-modified: scaffold skips it; use reapply --force)',
            ],
            'FRAGMENT_WARNING' => [
                [],
                'warning',
            ],
            'FRAGMENT_WARNINGS' => [
                [],
                'warnings',
            ],
            'FRAGMENT_WOULD_BE_WRITTEN' => [
                [],
                'would be written',
            ],
            'FRAGMENT_WRITTEN' => [
                [],
                'written',
            ],
            'PROVIDERS_NONE' => [
                [],
                '[scaffold] No providers tracked in scaffold-lock.json.',
            ],
            'PRUNE_DELETED' => [
                ['a.txt', ''],
                '[scaffold] Deleted "a.txt".',
            ],
            'PRUNE_DIRECTORY_REMOVE_FAILED' => [
                ['/project/config'],
                '[scaffold] Could not remove empty directory "/project/config".',
            ],
            'PRUNE_DRY_RUN_SUMMARY' => [
                [2, 1, 0, 'errors'],
                '[scaffold] prune --dry-run: 2 would be removed, 1 would be kept (modified), 0 errors; run with '
                . '--force to apply.',
            ],
            'PRUNE_ENTRY_DROPPED' => [
                ['a.txt', 'missing'],
                '[scaffold] Dropped the entry of "a.txt" (missing).',
            ],
            'PRUNE_FILE_DELETE_FAILED' => [
                ['/project/a.txt'],
                '[scaffold] Could not delete "/project/a.txt".',
            ],
            'PRUNE_KEPT' => [
                ['a.txt', 'modified'],
                '[scaffold] Kept "a.txt" (modified); it is yours now and no longer tracked.',
            ],
            'PRUNE_NO_ORPHANS' => [
                [],
                '[scaffold] No orphaned files.',
            ],
            'PRUNE_SUMMARY' => [
                [2, 1, 0, 'errors'],
                '[scaffold] prune: 2 removed, 1 kept (modified), 0 errors.',
            ],
            'REAPPLY_ALREADY_UP_TO_DATE' => [
                ['a.txt'],
                '[scaffold] "a.txt" is already up to date.',
            ],
            'REAPPLY_APPLIED' => [
                ['a.txt'],
                '[scaffold] Reapplied "a.txt".',
            ],
            'REAPPLY_EJECTED' => [
                ['a.txt'],
                '[scaffold] "a.txt" is ejected. Use --force to manage it again.',
            ],
            'REAPPLY_FAILED' => [
                ['a.txt', 'Could not write "/project/a.txt".'],
                '[scaffold] Could not reapply "a.txt": Could not write "/project/a.txt". Skipping.',
            ],
            'REAPPLY_MERGE_STRATEGY_UNKNOWN' => [
                ['yaml', '.gitignore'],
                '[scaffold] Unknown merge strategy "yaml" for ".gitignore"; using the default for its name.',
            ],
            'REAPPLY_NO_MATCH' => [
                [],
                '[scaffold] No tracked files matched the given filter.',
            ],
            'REAPPLY_ORPHANED' => [
                ['a.txt'],
                '[scaffold] "a.txt" is orphaned; run "vendor/bin/scaffold prune" to review.',
            ],
            'REAPPLY_PRESERVE' => [
                ['a.txt'],
                '[scaffold] "a.txt" uses mode "preserve". Use --force to overwrite.',
            ],
            'REAPPLY_STUB_NOT_FOUND' => [
                ['/vendor/p/a/stubs/a.txt'],
                '[scaffold] Stub not found: "/vendor/p/a/stubs/a.txt". Skipping.',
            ],
            'REAPPLY_SUMMARY' => [
                ['', 3, 'written', 7, 1, ' (user-modified)', 0, 'errors'],
                '[scaffold] reapply: 3 written, 7 unchanged, 1 skipped (user-modified), 0 errors.',
            ],
            'REAPPLY_UNSAFE_ENTRY' => [
                ['../a.txt', 'Destination path "../a.txt" contains a traversal segment.'],
                '[scaffold] Unsafe lock entry for "../a.txt": Destination path "../a.txt" contains a traversal '
                . 'segment. Skipping.',
            ],
            'REAPPLY_USER_MODIFIED' => [
                ['a.txt'],
                '[scaffold] "a.txt" is user-modified. Use --force to overwrite.',
            ],
            'SCAFFOLD_ADOPTED_SKIPPED' => [
                ['a.txt'],
                '[scaffold] User-modified file skipped: "a.txt".',
            ],
            'SCAFFOLD_ALLOWED_ENTRY_IGNORED' => [
                [],
                '[scaffold] Non-string entry in allowed-packages ignored.',
            ],
            'SCAFFOLD_APPLY_FAILED' => [
                ['a.txt', 'Could not write "/project/a.txt".'],
                '[scaffold] Error applying "a.txt": Could not write "/project/a.txt".',
            ],
            'SCAFFOLD_AUTO_DISABLED' => [
                [],
                '[scaffold] Auto-scaffold disabled via "extra.scaffold.auto"; run "vendor/bin/scaffold" manually.',
            ],
            'SCAFFOLD_CONFIG_MISSING' => [
                [],
                '[scaffold] No extra.scaffold configuration found. Skipping scaffold.',
            ],
            'SCAFFOLD_CONFLICTS' => [
                [2, 'files have'],
                '[scaffold] 2 files have provider updates you have not taken (conflict); run "vendor/bin/scaffold '
                . 'diff --all".',
            ],
            'SCAFFOLD_NO_ALLOWED_PACKAGES' => [
                [],
                '[scaffold] No allowed-packages configured. Skipping scaffold.',
            ],
            'SCAFFOLD_ORPHAN_ENTRY' => [
                ['a.txt', 'p/a'],
                '[scaffold]   a.txt (p/a)',
            ],
            'SCAFFOLD_ORPHAN_HEADER' => [
                [2, 'files'],
                '[scaffold] 2 orphaned files no allowed provider ships anymore; run "vendor/bin/scaffold prune" to '
                . 'review.',
            ],
            'SCAFFOLD_ORPHAN_MORE' => [
                [3],
                '[scaffold]   ... and 3 more',
            ],
            'SCAFFOLD_OVERRIDE' => [
                ['a.txt', 'p/b', 'p/a'],
                '[scaffold] "a.txt" from "p/b" overrides "p/a" (last provider in allowed-packages wins).',
            ],
            'SCAFFOLD_PERMISSIONS_COPY_FAILED' => [
                ['/vendor/p/a/stubs/bin/run', '/project/bin/run'],
                'Could not copy permissions from "/vendor/p/a/stubs/bin/run" to "/project/bin/run".',
            ],
            'SCAFFOLD_PREFIXED' => [
                ['User-modified file skipped: "a.txt".'],
                '[scaffold] User-modified file skipped: "a.txt".',
            ],
            'SCAFFOLD_PROVIDER_INSTALL_PATH_MISSING' => [
                ['p/a'],
                '[scaffold] Provider "p/a" has no install path (metapackage); skipping.',
            ],
            'SCAFFOLD_PROVIDER_MAPPINGS_EMPTY' => [
                ['p/a'],
                '[scaffold] Provider "p/a" produced no file mappings; check its copy[] entries and excludes.',
            ],
            'SCAFFOLD_PROVIDER_NOT_INSTALLED' => [
                ['p/a'],
                '[scaffold] Provider "p/a" is allowed but not installed; check extra.scaffold.allowed-packages or '
                . 'require it.',
            ],
            'SCAFFOLD_PROVIDER_ROOT_OUTSIDE_VENDOR' => [
                ['p/a'],
                '[scaffold] Provider root for "p/a" resolves outside vendor dir; using default path.',
            ],
            'SCAFFOLD_SUMMARY' => [
                ['', '3 files written, 8 unchanged, 1 conflict, 1 warning'],
                '[scaffold] 3 files written, 8 unchanged, 1 conflict, 1 warning.',
            ],
            'SCAFFOLD_UNTRACKED_OVERWRITTEN' => [
                ['a.txt'],
                'Overwrote untracked existing file "a.txt"; review it with git diff or run "scaffold eject a.txt '
                . '--yes" to keep your version.',
            ],
            'SCAFFOLD_USER_MODIFIED_SKIPPED' => [
                ['a.txt'],
                'User-modified file skipped: "a.txt".',
            ],
            'SCAFFOLD_VENDOR_DIR_UNRESOLVABLE' => [
                [],
                '[scaffold] Unable to resolve vendor-dir from Composer config; aborting scaffold.',
            ],
            'STATUS_FORMAT_UNKNOWN' => [
                ['xml'],
                '[scaffold] Unknown format "xml"; use "table" or "json".',
            ],
            'STATUS_NO_FILES' => [
                [],
                '[scaffold] No files tracked in scaffold-lock.json.',
            ],
            'STATUS_PROVIDER_DRIFT' => [
                ['p/a', '1.0.0', '1.1.0'],
                '[scaffold] p/a: lock 1.0.0, installed 1.1.0 (run composer install).',
            ],
            'STATUS_STUB_HASH_MISSING' => [
                [2, 'entries lack'],
                '[scaffold] 2 entries lack stub-hash; run composer install to refresh.',
            ],
            'STATUS_SUMMARY' => [
                [3, 'files', '2 synced, 1 modified'],
                '[scaffold] 3 files: 2 synced, 1 modified.',
            ],
            'SYNC_ABORTED' => [
                ['Could not write lock file "/project/scaffold-lock.json".'],
                '[scaffold] Sync aborted: Could not write lock file "/project/scaffold-lock.json".',
            ],
            'SYNC_DRY_RUN_SUMMARY' => [
                [2, 'files', 5, 1, 0, 'errors'],
                '[scaffold] sync --dry-run: 2 files would change, 5 unchanged, 1 orphan, 0 errors.',
            ],
            'SYNC_NO_ALLOWED_PACKAGES' => [
                [],
                '[scaffold] No allowed-packages configured. Nothing to sync.',
            ],
        ];
    }
}
