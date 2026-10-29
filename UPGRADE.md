# Upgrading Scaffold

This file lists, for each release, the changes that need an action from you or that change what you see when scaffold
runs. Features that need neither are described in the [documentation](README.md#documentation) and the
[changelog](CHANGELOG.md).

## Upgrading from yii2-extensions/scaffold 0.2.x to php-forge/scaffold 0.3.x

### Rename the package

The package moved from `yii2-extensions/scaffold` to `php-forge/scaffold`. Composer cannot rename a package, so change
both keys in the same commit; a project whose `allow-plugins` still names only the old package fails non-interactive
installs because the new plugin is not allowed.

```json
{
    "require-dev": {
        "php-forge/scaffold": "^0.3"
    },
    "config": {
        "allow-plugins": {
            "php-forge/scaffold": true
        }
    }
}
```

Remove `yii2-extensions/scaffold` from `require-dev` and from `config.allow-plugins`, then run:

```bash
composer update php-forge/scaffold --with-all-dependencies
vendor/bin/scaffold status --exit-code
git diff
```

`vendor/bin/scaffold`, `extra.scaffold`, provider manifests and `scaffold-lock.json` keep their names and formats. The
PHP namespace moved from `yii\scaffold` to `PHPForge\Scaffold`; only code that imports the plugin classes is affected.

### Dependencies

- `symfony/console` `^7.4 || ^8.0` (Symfony Console 8 requires PHP 8.4).
- [`php-forge/diff`](https://github.com/php-forge/diff) renders the unified diffs with Git's default algorithm (Myers
  with the indent heuristic).
- `composer/composer` and `sebastian/diff` are no longer installed with the plugin. A project that relied on them
  transitively must require them itself.
- Require the plugin in `require-dev`: with `require`, a fresh `composer install --no-dev` scaffolds production builds.

### First run after the upgrade

The first `composer update` or `composer install` with 0.3 can change files that 0.2 left alone. Review `git diff`:

- `append` files are now merged on every run with a format-aware strategy (`ini` for `.editorconfig`, `list` for
  `.gitignore` and other ignore files, `text` otherwise), so provider entries that 0.2 never delivered are added.
  Existing keys and entries are never changed or duplicated. See [File Modes](docs/modes.md#append).
- `prepend` is idempotent: the block is added only when the file does not contain it.
- A `replace` file that exists but is not tracked yet is still overwritten on adoption, now with an
  `Overwrote untracked existing file "<file>"` warning that points to `git diff` and `eject`.
- Lock entries gain `stub-hash` when they are applied again. Until then `status` prints
  `[scaffold] N entries lack stub-hash; run composer install to refresh.`

### scaffold-lock.json

The format is additive; a 0.2 lock is read as is. Entries gain `stub-hash` (hash of the provider source at the last
apply), and, when they apply, `merge`, `ejected: true` and `adopted: true`. The file is now written with sorted keys and
a trailing newline, so the first write produces a reordering diff. Unknown keys are kept. See
[Configuration Reference](docs/configuration.md#scaffold-lockjson).

### Composer runs

- A run that writes files or needs attention prints a summary line, such as
  `[scaffold] 2 files written, 7 unchanged, 1 conflict, 1 orphan, 1 warning.`; 0.2 printed nothing on success.
- These failures now fail the Composer command with exit code `1` instead of a message and exit code `0`: an unreadable
  or conflicted `scaffold-lock.json`, an invalid manifest of an allowed provider (nothing is applied), and a file that
  cannot be applied.
- Provider manifests with unknown keys are rejected. Check the providers you maintain: a typo such as `mode` instead of
  `modes` used to be ignored silently.
- An allowed provider that is not installed is reported as
  `Provider "<name>" is allowed but not installed; check extra.scaffold.allowed-packages or require it.`
- Files that no allowed provider ships anymore are listed as orphans on every run, with the `prune` hint; they are never
  deleted by Composer.
- `post-create-project-cmd` no longer applies a second time after `post-update-cmd`, so templates without
  `composer.lock` no longer get duplicated `prepend` blocks.

### Console commands

- `diff` reversed its direction: it shows what applying the provider would change, from the current file (`a/`) to the
  result of the apply (`b/`). 0.2 diffed from the stub to the current file. It needs a `<file>` or `--all`; without
  either it exits with `2`.
- `status` has new states (`pending`, `outdated`, `conflict`, `orphan`, `ejected`), a summary line, provider version
  drift on stderr, `--format=json`, and exits with `1` when an entry is in the `error` state. Add
  `vendor/bin/scaffold status --exit-code` to CI to fail on drift.
- `reapply` merges `append` and `prepend` files instead of refusing them, rewrites them from the stub with `--force`,
  skips orphans, ends with a summary line, and exits with `1` when an entry is skipped for an error (provider not
  allowed, unsafe entry, missing stub, unknown mode, read or write failure). It never reads providers that are not in
  `allowed-packages`.
- `eject` is durable: the entry stays in the lock with `ejected: true`, and no Composer run writes the file again. In
  0.2 the entry was removed and the next `composer install` overwrote the file. `reapply <file> --force` takes it back.
- `providers` sizes its columns to their content, like the `status` and `prune` tables.
- New commands: `sync` (apply the providers outside Composer, the bootstrap for `auto: false`) and `prune` (review and
  remove orphans).
- Every command finds the project root by walking up to the nearest `composer.json`, and accepts the global
  `--working-dir` (`-d`) option.

### Providers

- New manifest key `merge` selects the `append` strategy per destination.
- Brace alternatives `{a,b}` now work in `exclude`, `modes` and `merge` patterns.
- Default excludes gained `.git` as a file, `node_modules/**`, `.idea/**` and `.DS_Store`.
