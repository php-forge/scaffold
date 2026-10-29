<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console;

use PHPForge\Scaffold\MessageTemplate;

/**
 * Represents the message templates of the console output written by the scaffold plugin.
 */
enum Message: string
{
    use MessageTemplate;

    /**
     * Error when the current working directory cannot be determined.
     */
    case COMMAND_CWD_UNRESOLVABLE = '[scaffold] Unable to determine current working directory.';

    /**
     * Error when a tracked file cannot be previewed.
     */
    case COMMAND_FILE_ERROR = '[scaffold] "%s": %s';

    /**
     * Error when a file is not tracked in the lock file.
     */
    case COMMAND_FILE_NOT_TRACKED = '[scaffold] "%s" is not tracked in scaffold-lock.json.';

    /**
     * Error when no Composer manifest is found in the start directory or any of its parents.
     */
    case COMMAND_MANIFEST_NOT_FOUND = '[scaffold] No %s found in "%s" or any parent directory.';

    /**
     * Error when the provider of a lock entry is not in `allowed-packages`.
     */
    case COMMAND_PROVIDER_NOT_ALLOWED = '[scaffold] "%s": provider "%s" is not in extra.scaffold.allowed-packages; '
        . 'skipped.';

    /**
     * Error when a lock entry fails path validation.
     */
    case COMMAND_UNSAFE_ENTRY = '[scaffold] Unsafe lock entry for "%s": %s';

    /**
     * Error when the `--working-dir` directory does not exist.
     */
    case COMMAND_WORKING_DIR_NOT_FOUND = '[scaffold] Working directory "%s" does not exist.';

    /**
     * Notice when applying the provider would create an empty file.
     */
    case DIFF_CREATED_EMPTY = '[scaffold] %s: would be created empty.';

    /**
     * Notice when the requested file is ejected.
     */
    case DIFF_EJECTED = '[scaffold] %s: ejected; scaffold does not manage it (see reapply --force).';

    /**
     * Notice when a file differs from the provider only in its line endings.
     */
    case DIFF_LINE_ENDINGS_ONLY = '[scaffold] %s: differs only in line endings.';

    /**
     * Error when the manifest of the provider of a tracked file could not be loaded.
     */
    case DIFF_MANIFEST_NOT_LOADED = '[scaffold] "%s": the manifest of provider "%s" could not be loaded; skipped.';

    /**
     * Notice when applying the provider would not change a file.
     */
    case DIFF_NO_CHANGES = '[scaffold] %s: no changes.';

    /**
     * Notice when no allowed provider ships a tracked file anymore.
     */
    case DIFF_ORPHAN = '[scaffold] %s: orphan; no allowed provider ships it anymore.';

    /**
     * Header printed before the diff of a file that applying the provider would change.
     */
    case DIFF_PREVIEW_HEADER = '[scaffold] %s (%s, %s): changes applying the provider would make%s';

    /**
     * Error when neither or both of a file and `--all` are given.
     */
    case DIFF_TARGET_AMBIGUOUS = '[scaffold] Pass either a file or --all.';

    /**
     * Notice when the file is already ejected.
     */
    case EJECT_ALREADY_EJECTED = '[scaffold] "%s" is already ejected.';

    /**
     * Notice that previews an ejection that still needs `--yes`.
     */
    case EJECT_CONFIRMATION_REQUIRED = '[scaffold] Would eject "%s": it will not be managed until reapplied with '
        . '--force. Run with --yes to confirm.';

    /**
     * Notice when a file is ejected.
     */
    case EJECT_EJECTED = '[scaffold] Ejected "%s": it will not be managed until reapplied with --force. The file was '
        . 'not deleted from disk.';

    /**
     * Fragment that names the command in a summary line.
     */
    case FRAGMENT_COMMAND = '%s: ';

    /**
     * Fragment that labels a conflict count.
     */
    case FRAGMENT_CONFLICT = 'conflict';

    /**
     * Fragment that names one file with untaken provider updates.
     */
    case FRAGMENT_CONFLICT_FILE = 'file has';

    /**
     * Fragment that names several files with untaken provider updates.
     */
    case FRAGMENT_CONFLICT_FILES = 'files have';

    /**
     * Fragment that pairs a count with its label.
     */
    case FRAGMENT_COUNT = '%d %s';

    /**
     * Fragment that counts unchanged files.
     */
    case FRAGMENT_COUNT_UNCHANGED = '%d unchanged';

    /**
     * Fragment that counts written files.
     */
    case FRAGMENT_COUNT_WRITTEN = '%d %s written';

    /**
     * Fragment that marks a dry run.
     */
    case FRAGMENT_DRY_RUN = ' --dry-run';

    /**
     * Fragment that names several lock entries without `stub-hash`.
     */
    case FRAGMENT_ENTRIES_LACK = 'entries lack';

    /**
     * Fragment that names one lock entry without `stub-hash`.
     */
    case FRAGMENT_ENTRY_LACKS = 'entry lacks';

    /**
     * Fragment that labels one error.
     */
    case FRAGMENT_ERROR = 'error';

    /**
     * Fragment that labels several errors.
     */
    case FRAGMENT_ERRORS = 'errors';

    /**
     * Fragment that labels one file.
     */
    case FRAGMENT_FILE = 'file';

    /**
     * Fragment that labels several files.
     */
    case FRAGMENT_FILES = 'files';

    /**
     * Fragment that labels an orphan count.
     */
    case FRAGMENT_ORPHAN = 'orphan';

    /**
     * Fragment that labels an outdated count.
     */
    case FRAGMENT_OUTDATED = 'outdated';

    /**
     * Fragment that appends a parenthesized detail.
     */
    case FRAGMENT_PARENTHESIZED = ' (%s)';

    /**
     * Fragment that marks the preview of an existing `preserve` file.
     */
    case FRAGMENT_PRESERVE_PREVIEW = ' (preserve: applied only with reapply --force)';

    /**
     * Fragment that stands for an unknown provider.
     */
    case FRAGMENT_UNKNOWN_PROVIDER = '?';

    /**
     * Fragment that marks the preview of a user-modified file.
     */
    case FRAGMENT_USER_MODIFIED_PREVIEW = ' (user-modified: scaffold skips it; use reapply --force)';

    /**
     * Fragment that labels one warning.
     */
    case FRAGMENT_WARNING = 'warning';

    /**
     * Fragment that labels several warnings.
     */
    case FRAGMENT_WARNINGS = 'warnings';

    /**
     * Fragment that labels the files a dry run would write.
     */
    case FRAGMENT_WOULD_BE_WRITTEN = 'would be written';

    /**
     * Fragment that labels the written files.
     */
    case FRAGMENT_WRITTEN = 'written';

    /**
     * Notice when the lock file tracks no provider.
     */
    case PROVIDERS_NONE = '[scaffold] No providers tracked in scaffold-lock.json.';

    /**
     * Notice when an orphan file is deleted.
     */
    case PRUNE_DELETED = '[scaffold] Deleted "%s"%s.';

    /**
     * Warning when an empty parent directory cannot be removed.
     */
    case PRUNE_DIRECTORY_REMOVE_FAILED = '[scaffold] Could not remove empty directory "%s".';

    /**
     * Summary line of a `prune` dry run.
     */
    case PRUNE_DRY_RUN_SUMMARY = '[scaffold] prune --dry-run: %d would be removed, %d would be kept (modified), %d %s; '
        . 'run with --force to apply.';

    /**
     * Notice when the lock entry of a missing or unsafe orphan is dropped.
     */
    case PRUNE_ENTRY_DROPPED = '[scaffold] Dropped the entry of "%s" (%s).';

    /**
     * Error when an orphan file cannot be deleted.
     */
    case PRUNE_FILE_DELETE_FAILED = '[scaffold] Could not delete "%s".';

    /**
     * Notice when a modified orphan file is kept and no longer tracked.
     */
    case PRUNE_KEPT = '[scaffold] Kept "%s" (%s); it is yours now and no longer tracked.';

    /**
     * Notice when there is no orphan file.
     */
    case PRUNE_NO_ORPHANS = '[scaffold] No orphaned files.';

    /**
     * Summary line of a `prune` run.
     */
    case PRUNE_SUMMARY = '[scaffold] prune: %d removed, %d kept (modified), %d %s.';

    /**
     * Notice when an `append` or `prepend` file already holds the provider content.
     */
    case REAPPLY_ALREADY_UP_TO_DATE = '[scaffold] "%s" is already up to date.';

    /**
     * Notice when a file is reapplied.
     */
    case REAPPLY_APPLIED = '[scaffold] Reapplied "%s".';

    /**
     * Warning when an ejected file is skipped.
     */
    case REAPPLY_EJECTED = '[scaffold] "%s" is ejected. Use --force to manage it again.';

    /**
     * Error when an `append` or `prepend` file cannot be reapplied.
     */
    case REAPPLY_FAILED = '[scaffold] Could not reapply "%s": %s Skipping.';

    /**
     * Warning when a lock entry records an unknown merge strategy.
     */
    case REAPPLY_MERGE_STRATEGY_UNKNOWN = '[scaffold] Unknown merge strategy "%s" for "%s"; using the default for its '
        . 'name.';

    /**
     * Error when no tracked file matches the file or provider filter.
     */
    case REAPPLY_NO_MATCH = '[scaffold] No tracked files matched the given filter.';

    /**
     * Warning when an orphaned file is skipped.
     */
    case REAPPLY_ORPHANED = '[scaffold] "%s" is orphaned; run "vendor/bin/scaffold prune" to review.';

    /**
     * Warning when an existing `preserve` file is skipped.
     */
    case REAPPLY_PRESERVE = '[scaffold] "%s" uses mode "preserve". Use --force to overwrite.';

    /**
     * Error when the provider stub of a file does not exist.
     */
    case REAPPLY_STUB_NOT_FOUND = '[scaffold] Stub not found: "%s". Skipping.';

    /**
     * Summary line of a `reapply` run.
     */
    case REAPPLY_SUMMARY = '[scaffold] reapply%s: %d %s, %d unchanged, %d skipped%s, %d %s.';

    /**
     * Error when a lock entry fails path validation and is skipped.
     */
    case REAPPLY_UNSAFE_ENTRY = '[scaffold] Unsafe lock entry for "%s": %s Skipping.';

    /**
     * Warning when a user-modified file is skipped.
     */
    case REAPPLY_USER_MODIFIED = '[scaffold] "%s" is user-modified. Use --force to overwrite.';

    /**
     * Warning when an adopted `replace` file is skipped.
     */
    case SCAFFOLD_ADOPTED_SKIPPED = '[scaffold] User-modified file skipped: "%s".';

    /**
     * Warning when a non-string `allowed-packages` entry is ignored.
     */
    case SCAFFOLD_ALLOWED_ENTRY_IGNORED = '[scaffold] Non-string entry in allowed-packages ignored.';

    /**
     * Error when a file mapping fails to apply.
     */
    case SCAFFOLD_APPLY_FAILED = '[scaffold] Error applying "%s": %s';

    /**
     * Notice when `extra.scaffold.auto` disables the Composer hooks.
     */
    case SCAFFOLD_AUTO_DISABLED = '[scaffold] Auto-scaffold disabled via "extra.scaffold.auto"; run '
        . '"vendor/bin/scaffold" manually.';

    /**
     * Notice when the root package has no `extra.scaffold` configuration.
     */
    case SCAFFOLD_CONFIG_MISSING = '[scaffold] No extra.scaffold configuration found. Skipping scaffold.';

    /**
     * Warning when user-modified files skip provider updates.
     */
    case SCAFFOLD_CONFLICTS = '[scaffold] %d %s provider updates you have not taken (conflict); run '
        . '"vendor/bin/scaffold diff --all".';

    /**
     * Notice when `allowed-packages` is empty or missing.
     */
    case SCAFFOLD_NO_ALLOWED_PACKAGES = '[scaffold] No allowed-packages configured. Skipping scaffold.';

    /**
     * Line that lists one orphaned file with its provider.
     */
    case SCAFFOLD_ORPHAN_ENTRY = '[scaffold]   %s (%s)';

    /**
     * Warning that announces the orphaned files with the `prune` hint.
     */
    case SCAFFOLD_ORPHAN_HEADER = '[scaffold] %d orphaned %s no allowed provider ships anymore; run '
        . '"vendor/bin/scaffold prune" to review.';

    /**
     * Line that counts the orphaned files left out of the list.
     */
    case SCAFFOLD_ORPHAN_MORE = '[scaffold]   ... and %d more';

    /**
     * Notice when a later provider overrides the destination of an earlier one.
     */
    case SCAFFOLD_OVERRIDE = '[scaffold] "%s" from "%s" overrides "%s" (last provider in allowed-packages wins).';

    /**
     * Warning when the source permissions cannot be copied onto the destination.
     */
    case SCAFFOLD_PERMISSIONS_COPY_FAILED = 'Could not copy permissions from "%s" to "%s".';

    /**
     * Line that prefixes a message with `[scaffold]`.
     */
    case SCAFFOLD_PREFIXED = '[scaffold] %s';

    /**
     * Warning when an allowed provider has no install path.
     */
    case SCAFFOLD_PROVIDER_INSTALL_PATH_MISSING = '[scaffold] Provider "%s" has no install path (metapackage); '
        . 'skipping.';

    /**
     * Warning when a provider manifest produces no file mapping.
     */
    case SCAFFOLD_PROVIDER_MAPPINGS_EMPTY = '[scaffold] Provider "%s" produced no file mappings; check its copy[] '
        . 'entries and excludes.';

    /**
     * Warning when an allowed provider is not installed.
     */
    case SCAFFOLD_PROVIDER_NOT_INSTALLED = '[scaffold] Provider "%s" is allowed but not installed; check '
        . 'extra.scaffold.allowed-packages or require it.';

    /**
     * Warning when the recorded provider root resolves outside the vendor directory.
     */
    case SCAFFOLD_PROVIDER_ROOT_OUTSIDE_VENDOR = '[scaffold] Provider root for "%s" resolves outside vendor dir; using '
        . 'default path.';

    /**
     * Summary line of a scaffold run.
     */
    case SCAFFOLD_SUMMARY = '[scaffold] %s%s.';

    /**
     * Warning when an untracked existing file is overwritten.
     */
    case SCAFFOLD_UNTRACKED_OVERWRITTEN = 'Overwrote untracked existing file "%1$s"; review it with git diff or run '
        . '"scaffold eject %1$s --yes" to keep your version.';

    /**
     * Warning when a user-modified `replace` file is skipped.
     */
    case SCAFFOLD_USER_MODIFIED_SKIPPED = 'User-modified file skipped: "%s".';

    /**
     * Error when the vendor directory cannot be resolved from the Composer configuration.
     */
    case SCAFFOLD_VENDOR_DIR_UNRESOLVABLE = '[scaffold] Unable to resolve vendor-dir from Composer config; aborting '
        . 'scaffold.';

    /**
     * Error when `--format` is neither `table` nor `json`.
     */
    case STATUS_FORMAT_UNKNOWN = '[scaffold] Unknown format "%s"; use "table" or "json".';

    /**
     * Notice when the lock file tracks no file.
     */
    case STATUS_NO_FILES = '[scaffold] No files tracked in scaffold-lock.json.';

    /**
     * Warning when the locked provider version differs from the installed one.
     */
    case STATUS_PROVIDER_DRIFT = '[scaffold] %s: lock %s, installed %s (run composer install).';

    /**
     * Notice when lock entries lack `stub-hash`.
     */
    case STATUS_STUB_HASH_MISSING = '[scaffold] %d %s stub-hash; run composer install to refresh.';

    /**
     * Summary line of the status table.
     */
    case STATUS_SUMMARY = '[scaffold] %d %s: %s.';

    /**
     * Error when a `sync` run aborts.
     */
    case SYNC_ABORTED = '[scaffold] Sync aborted: %s';

    /**
     * Summary line of a `sync` dry run.
     */
    case SYNC_DRY_RUN_SUMMARY = '[scaffold] sync --dry-run: %d %s would change, %d unchanged, %d orphan, %d %s.';

    /**
     * Notice when `allowed-packages` is empty or missing.
     */
    case SYNC_NO_ALLOWED_PACKAGES = '[scaffold] No allowed-packages configured. Nothing to sync.';
}
