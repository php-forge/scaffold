# Console commands

The plugin ships a standalone [Symfony Console](https://symfony.com/doc/current/components/console.html) application at
`vendor/bin/scaffold`. It needs no framework bootstrap and works in any Composer project. Run `vendor/bin/scaffold list`
for the command list and `vendor/bin/scaffold <command> --help` for the options of one command.

| Command     | Purpose                                                                             |
| ----------- | ----------------------------------------------------------------------------------- |
| `status`    | Shows the state of every tracked and pending file; `--exit-code` gates CI.          |
| `diff`      | Shows what applying the provider would change, as a Git-style unified diff.         |
| `reapply`   | Applies the stubs of tracked files again; `--force` takes user-modified files back. |
| `eject`     | Marks a file as owned by the project, so scaffold never writes it again.            |
| `prune`     | Lists the files no allowed provider ships anymore; `--force` removes them.          |
| `sync`      | Applies every allowed provider outside Composer, as `composer install` would.       |
| `providers` | Lists the providers recorded in `scaffold-lock.json` with their file counts.        |

## Project root and global options

Every command looks for the project root by walking up from the current directory to the nearest `composer.json` (or the
file named by the `COMPOSER` environment variable), so `cd src && ../vendor/bin/scaffold status` works. The global
option `--working-dir=<path>` (`-d <path>`) starts the search from another directory:

```bash
vendor/bin/scaffold --working-dir=../app status
```

The vendor directory follows `COMPOSER_VENDOR_DIR`, then `config.vendor-dir`, then `vendor`. Provider roots come from
the project's `vendor/composer/installed.php`, so custom installers and symlinked path repositories resolve correctly;
the path recorded in the lock is the fallback.

Every command except `eject` and `providers` reads only the providers listed in `extra.scaffold.allowed-packages`.
`status`, `diff` and `reapply` report a lock entry whose provider is no longer allowed on stderr
(`[scaffold] "<file>": provider "<name>" is not in extra.scaffold.allowed-packages; skipped.`); `diff` and `reapply`
then exit with `1` without touching the file. `prune` lists such entries as orphans.

Tables, diffs and summary lines go to stdout; notices, warnings and errors go to stderr, prefixed with `[scaffold]`. On
an ANSI-capable terminal, diffs are colorized; pass `--no-ansi` for plain output.

## status

```text
vendor/bin/scaffold status [--format=table|json] [--exit-code]
```

Compares every tracked file with the disk and with the current provider stubs, and lists the provider files that are not
tracked yet. Output after a provider release that changed `.styleci.yml` (edited in the project) and dropped
`.github/linters/.gitleaks.toml`:

```text
File                                        Provider                   Mode      Status
-----------------------------------------------------------------------------------------
.editorconfig                               php-forge/baseline         append    synced
.gitattributes                              php-forge/baseline         replace   synced
.github/linters/.codespellrc                php-forge/baseline         replace   synced
.github/linters/.editorconfig-checker.json  php-forge/baseline         replace   synced
.github/linters/.gitleaks.toml              php-forge/baseline         replace   orphan
.github/linters/.markdown-lint.yml          php-forge/baseline         replace   synced
.gitignore                                  php-forge/baseline         append    synced
.styleci.yml                                php-forge/baseline         replace   conflict
composer-require-checker.json               php-forge/baseline         preserve  synced
ecs.php                                     php-forge/coding-standard  preserve  synced
rector.php                                  php-forge/coding-standard  preserve  synced
[scaffold] 11 files: 9 synced, 1 conflict, 1 orphan.
```

The generated
[`FileStatus` API reference](https://yii2-framework.github.io/docs/extensions/scaffold/reference/services/filestatus/)
defines every state. A tracked file gets the first applicable state in this evaluation order: `ejected`, `error` for an
unsafe lock destination, `orphan`, `missing`, `error` for a read, manifest or stub failure, `conflict`, `modified`,
`outdated`, then `synced`. After tracked entries, provider mappings without a lock entry are reported as `pending`.

Notices on stderr:

- Provider version drift between the lock and the installed package:
  `[scaffold] php-forge/baseline: lock 0.2.0, installed 0.2.1 (run composer install).`
- Entries written before stub hashes were recorded:
  `[scaffold] 3 entries lack stub-hash; run composer install to refresh.`
- Lock entries of providers that are no longer allowed, and manifests that cannot be loaded.

`--format=json` prints one JSON document on stdout and nothing else:

```json
{
    "files": {
        ".styleci.yml": {
            "provider": "php-forge/baseline",
            "mode": "replace",
            "status": "conflict"
        }
    },
    "providers": {
        "php-forge/baseline": {
            "lock": "0.2.1",
            "installed": "0.2.1"
        }
    },
    "summary": {
        "total": 11,
        "synced": 9,
        "pending": 0,
        "outdated": 0,
        "modified": 0,
        "conflict": 1,
        "missing": 0,
        "orphan": 1,
        "ejected": 0,
        "error": 0
    }
}
```

| Key         | Type                                                                                                      |
| ----------- | --------------------------------------------------------------------------------------------------------- |
| `files`     | Object keyed by destination; each value has `provider`, `mode` and `status` (one of the states above).    |
| `providers` | Object keyed by the providers recorded in the lock; `lock` and `installed` are version strings or `null`. |
| `summary`   | `total` and one count per state, always all ten keys, in the order shown.                                 |

`status` exits with `1` when any file is in the `error` state. With `--exit-code`, it also exits with `1` when any file
is neither `synced` nor `ejected`, `pending` included; this is the CI gate:

```bash
vendor/bin/scaffold status --exit-code
```

## diff

```text
vendor/bin/scaffold diff <file> | --all [--exit-code]
```

Shows what applying the provider would change right now, as a Git-style unified diff from the current file (`a/<file>`)
to the result of the apply (`b/<file>`), rendered by [php-forge/diff](https://github.com/php-forge/diff) with Git's
default algorithm. `--all` covers every tracked, non-ejected file in lock order and reports orphans without diffing
them. Pass exactly one of `<file>` and `--all`.

```text
[scaffold] .github/linters/.gitleaks.toml: orphan; no allowed provider ships it anymore.
[scaffold] .gitignore: no changes.
[scaffold] .styleci.yml (replace, php-forge/baseline): changes applying the provider would make (user-modified: scaffold skips it; use reapply --force)
--- a/.styleci.yml
+++ b/.styleci.yml
@@ -1,5 +1,5 @@
-preset: psr12
-risky: false
+preset: per
+risky: true

 version: 8.3

```

- `replace` diffs to the stub. A user-modified file gets the suffix
  `(user-modified: scaffold skips it; use reapply --force)`.
- `preserve` diffs to the stub with the suffix `(preserve: applied only with reapply --force)`.
- `append` and `prepend` diff to the merge result, so a file that already holds every provider entry prints
  `[scaffold] <file>: no changes.`
- A missing file diffs from empty. A file that differs only in line endings prints `differs only in line endings.`
- An ejected file prints `[scaffold] <file>: ejected; scaffold does not manage it (see reapply --force).`

`diff` exits with `1` when the file is not tracked, an entry is unsafe, its provider is not allowed or its manifest
cannot be loaded, or a file cannot be read. With `--exit-code`, it also exits with `1` when applying would change any
file.

## reapply

```text
vendor/bin/scaffold reapply [<file>] [--provider=<name>] [--force] [--dry-run] [--exit-code]
```

Applies the stubs of the tracked files again and records the new hashes. `<file>` and `--provider` restrict the run to
one destination or one provider.

```text
[scaffold] ".editorconfig" is already up to date.
[scaffold] Reapplied ".gitattributes".
[scaffold] Reapplied ".github/linters/.codespellrc".
[scaffold] Reapplied ".github/linters/.editorconfig-checker.json".
[scaffold] Reapplied ".github/linters/.markdown-lint.yml".
[scaffold] ".gitignore" is already up to date.
[scaffold] reapply: 4 written, 2 unchanged, 5 skipped (1 orphaned, 1 user-modified, 3 preserve), 0 errors.
```

with the skip reasons on stderr:

```text
[scaffold] ".github/linters/.gitleaks.toml" is orphaned; run "vendor/bin/scaffold prune" to review.
[scaffold] ".styleci.yml" is user-modified. Use --force to overwrite.
[scaffold] "composer-require-checker.json" uses mode "preserve". Use --force to overwrite.
[scaffold] "ecs.php" uses mode "preserve". Use --force to overwrite.
[scaffold] "rector.php" uses mode "preserve". Use --force to overwrite.
```

- Without `--force`: `replace` files are rewritten unless they are user-modified or adopted; `append` and `prepend`
  files are merged as on a Composer run; existing `preserve` files and ejected files are skipped.
- With `--force`: every matching file is rewritten from its stub, user-modified, adopted, `preserve`, `append`,
  `prepend` and ejected files included; the `ejected` and `adopted` flags are cleared. Consumer additions to `append`
  and `prepend` files are lost.
- Orphans are always skipped, even with `--force`.
- `--dry-run` prints the diff of every file that would change, as `diff` does, and writes nothing. With `--exit-code`, a
  dry run exits with `1` when any file would change.

`reapply` exits with `1` when `<file>` or `--provider` matches no tracked file, or when an entry is skipped for an error
(unsafe entry, provider not allowed, missing stub, unknown mode, read, hash or write failure). Skips for user-modified,
`preserve`, ejected and orphaned files are not errors.

## eject

```text
vendor/bin/scaffold eject <file> [--yes]
```

Marks a tracked file as `ejected` in `scaffold-lock.json`. The file stays on disk and in the lock, and no Composer run,
`sync` or `reapply` without `--force` writes it again. `vendor/bin/scaffold reapply <file> --force` takes it back.

```text
$ vendor/bin/scaffold eject .styleci.yml
[scaffold] Would eject ".styleci.yml": it will not be managed until reapplied with --force. Run with --yes to confirm.
$ vendor/bin/scaffold eject .styleci.yml --yes
[scaffold] Ejected ".styleci.yml": it will not be managed until reapplied with --force. The file was not deleted from disk.
```

`eject` exits with `1` when the file is not tracked. Ejecting an ejected file prints `is already ejected.` and exits
with `0`.

## prune

```text
vendor/bin/scaffold prune [--force] [--delete-modified] [--exit-code]
```

Lists the orphans: tracked files that no allowed and installed provider ships anymore, because the provider dropped the
file, or the provider was removed from `allowed-packages` or uninstalled. Ejected files are never orphans. Without
`--force`, `prune` only prints a table:

```text
File                            Provider            Disk        With --force
----------------------------------------------------------------------------
.github/linters/.gitleaks.toml  php-forge/baseline  unmodified  delete
[scaffold] prune --dry-run: 1 would be removed, 0 would be kept (modified), 0 errors; run with --force to apply.
```

| Disk         | Meaning                                                                        | With `--force`                       |
| ------------ | ------------------------------------------------------------------------------ | ------------------------------------ |
| `unmodified` | The file holds only the provider content of the last apply.                    | `delete`                             |
| `modified`   | The file changed since the last apply.                                         | `keep` (`--delete-modified` deletes) |
| `merged`     | An `append` or `prepend` file that also holds consumer content.                | `keep` (`--delete-modified` deletes) |
| `adopted`    | The file existed before scaffold tracked it.                                   | `keep` (`--delete-modified` deletes) |
| `missing`    | The file is already gone.                                                      | `drop entry`                         |
| `unsafe`     | The destination in the lock fails path validation; nothing is touched on disk. | `drop entry`                         |
| `error`      | The destination cannot be read, for example a directory; nothing is touched.   | `keep` (never deleted)               |

```text
$ vendor/bin/scaffold prune --force
[scaffold] Deleted ".github/linters/.gitleaks.toml".
[scaffold] prune: 1 removed, 0 kept (modified), 0 errors.
```

- `--force` removes every orphan entry from the lock, deletes the `unmodified` files and the empty directories they
  leave (never the project root), keeps every other file on disk as yours, and removes the providers that have no entry
  left and are no longer allowed or installed.
- `--delete-modified` (destructive, only with `--force`) also deletes `modified`, `merged` and `adopted` orphans and
  prints each path.
- `--exit-code` (without `--force`) exits with `1` when orphans exist.

`prune` exits with `1` when a provider manifest cannot be loaded (the orphans of that provider are never pruned), an
orphan is in the `error` state, or a file cannot be deleted. An empty parent directory that cannot be removed is only
reported.

## sync

```text
vendor/bin/scaffold sync [--dry-run] [--exit-code] [--force]
```

Applies every allowed and installed provider exactly as a Composer run does, without Composer: for projects with
`auto: false`, installs run with `--no-plugins`, bots and CI. It creates `scaffold-lock.json` when it is missing.

```text
$ vendor/bin/scaffold sync --dry-run --exit-code
...
[scaffold] sync --dry-run: 10 files would change, 0 unchanged, 0 orphan, 0 errors.
$ vendor/bin/scaffold sync
[scaffold] sync: 10 files written, 0 unchanged.
```

- `--dry-run` prints, for every file the providers ship, the diff that applying it would make (as `diff`), then the
  orphans on stderr, and writes nothing. A file that is not tracked yet counts as a change.
- `--exit-code` (with `--dry-run`) exits with `1` when applying would write a file or add an entry to the lock.
- `--force` overwrites user-modified and adopted `replace` files, as `reapply --force` does.

`sync` exits with `1` when a provider manifest is invalid (nothing is applied), a file fails to apply, or the lock
cannot be read or written. With no `allowed-packages`, it prints `No allowed-packages configured. Nothing to sync.` and
exits with `0`.

## providers

```text
vendor/bin/scaffold providers
```

Lists the providers recorded in `scaffold-lock.json` with the number of tracked files of each:

```text
Provider                   Files
--------------------------------
php-forge/baseline         9
php-forge/coding-standard  2
```

## Typical workflow

```bash
composer update                              # install a provider release
vendor/bin/scaffold status                   # what needs attention
vendor/bin/scaffold diff --all               # what applying the providers would change
vendor/bin/scaffold reapply .styleci.yml --force   # take the provider version of a file
vendor/bin/scaffold eject .styleci.yml --yes       # or keep yours for good
vendor/bin/scaffold prune                    # review files no provider ships anymore
```

In CI, fail the build when the committed files drift from the providers:

```bash
vendor/bin/scaffold status --exit-code
```

## Exit codes

| Code | Meaning                                                                                                                                                                                                                                                                                    |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `0`  | Success, including previews, skips and runs that change nothing.                                                                                                                                                                                                                           |
| `1`  | Error: no project root, unreadable lock or `composer.json`, untracked file, unsafe entry, provider not allowed, invalid manifest, missing stub, write failure, `status` entry in the `error` state, unknown option or missing argument. With `--exit-code`, also drift or pending changes. |
| `2`  | Invalid input detected by the command: `diff` without a file and without `--all`, or with both; `status --format` other than `table` or `json`.                                                                                                                                            |

## Next steps

- 📖 [Readme](../README.md)
- 🔀 [File Modes](modes.md)
- ⚙️ [Configuration Reference](configuration.md)
- 📦 [Creating Providers](providers.md)
- 🧪 [Testing Guide](testing.md)
