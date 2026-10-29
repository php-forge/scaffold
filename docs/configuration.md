# Configuration reference

The consumer configuration lives under `extra.scaffold` in the root `composer.json` of the project. Only the root
package is read: the `extra.scaffold` block of a provider describes what it ships (see
[Creating Providers](providers.md)), and its own `allowed-packages` has no effect on the projects that install it.

## allowed-packages

An ordered list of the Composer packages allowed to write files into the project. Packages that are not listed are never
read, even when they ship a manifest.

```json
{
    "extra": {
        "scaffold": {
            "allowed-packages": ["php-forge/baseline", "php-forge/coding-standard"]
        }
    }
}
```

- **Order matters.** When two providers ship the same destination, the provider listed later wins, and the run prints
  `[scaffold] "<file>" from "<later>" overrides "<earlier>" (last provider in allowed-packages wins).`
- A listed package that is not installed produces a warning that names the fix:
  `[scaffold] Provider "<name>" is allowed but not installed; check extra.scaffold.allowed-packages or require it.`
- Entries that are not strings are ignored with `[scaffold] Non-string entry in allowed-packages ignored.`
- Without `extra.scaffold`, or with an empty or missing list, Composer runs print
  `[scaffold] No extra.scaffold configuration found. Skipping scaffold.` or
  `[scaffold] No allowed-packages configured. Skipping scaffold.` and write nothing.

## auto

Optional, defaults to `true`. Controls whether the plugin scaffolds on Composer events.

```json
{
    "extra": {
        "scaffold": {
            "auto": false,
            "allowed-packages": ["php-forge/baseline", "php-forge/coding-standard"]
        }
    }
}
```

With `auto: false`, Composer runs print
`[scaffold] Auto-scaffold disabled via "extra.scaffold.auto"; run "vendor/bin/scaffold" manually.` and write nothing.
The console commands keep working, and `sync` applies the providers on demand, creating `scaffold-lock.json` when it
does not exist yet:

```bash
vendor/bin/scaffold status                      # every provider file is listed as pending
vendor/bin/scaffold sync --dry-run --exit-code  # review what applying the providers would change
vendor/bin/scaffold sync                        # apply them, as composer install would
```

Use it when every change to the shared files must be reviewed before it lands. Only an explicit `false` disables the
Composer hooks; any other value, including a missing key or `null`, keeps them enabled, so a typo never disables
scaffolding silently.

## Full example

```json
{
    "require-dev": {
        "php-forge/baseline": "^0.2",
        "php-forge/coding-standard": "^0.3",
        "php-forge/scaffold": "^0.3"
    },
    "config": {
        "allow-plugins": {
            "php-forge/scaffold": true
        }
    },
    "extra": {
        "scaffold": {
            "allowed-packages": ["php-forge/baseline", "php-forge/coding-standard"]
        }
    }
}
```

## scaffold-lock.json

Every run that applies files writes `scaffold-lock.json` at the project root. It records which provider owns each file,
how it was written, and the hashes that scaffold compares against on the next run. Commit it: `status`, `diff`, `prune`
and the user-modified detection of every clone and CI job read it.

Excerpt from a project that installed `php-forge/baseline` and `php-forge/coding-standard` with an existing `ecs.php`:

```json
{
    "providers": {
        "php-forge/baseline": {
            "version": "0.2.0",
            "path": "vendor/php-forge/baseline"
        },
        "php-forge/coding-standard": {
            "version": "0.3.4",
            "path": "vendor/php-forge/coding-standard"
        }
    },
    "files": {
        ".gitignore": {
            "hash": "sha256:4d07dd19f50ed675620f5a2f2d3abc9d2d3df9fe4ffbf70efc7a7efabfdbdc7c",
            "provider": "php-forge/baseline",
            "source": "metadata/.gitignore",
            "mode": "append",
            "stub-hash": "sha256:7d7753b2cc6184d74fb22f78c6b351b6c9c9dc04bcabe5481a0ed64b06858597"
        },
        ".styleci.yml": {
            "hash": "sha256:825f8c744ffbd2bc2369712e7192b8694129a8ecbc3fd91dc9300fccecf827bd",
            "provider": "php-forge/baseline",
            "source": "metadata/.styleci.yml",
            "mode": "replace",
            "stub-hash": "sha256:825f8c744ffbd2bc2369712e7192b8694129a8ecbc3fd91dc9300fccecf827bd"
        },
        "ecs.php": {
            "hash": "sha256:50b842500050094116202088109abfac734d2fd9341eb14b25dd870fc63936f3",
            "provider": "php-forge/coding-standard",
            "source": "src/config/ecs.php",
            "mode": "preserve",
            "stub-hash": "sha256:7c054b397506c1a4d4ab2d1197e9536b44f114e2721a0e9f8c46f362efcc7bae",
            "adopted": true
        }
    }
}
```

`providers.<name>`:

| Key       | Meaning                                                                                                   |
| --------- | --------------------------------------------------------------------------------------------------------- |
| `version` | Pretty version of the provider at the last run, as Composer reports it.                                   |
| `path`    | Install path of the provider, relative to the project root, or absolute when it lies outside the project. |

`files.<destination>`:

| Key         | Meaning                                                                                                          |
| ----------- | ---------------------------------------------------------------------------------------------------------------- |
| `hash`      | `sha256:<hex>` of the destination as scaffold last left it (see [Hash tracking](modes.md#hash-tracking)).        |
| `provider`  | Package that owns the destination.                                                                               |
| `source`    | Path of the stub inside the provider.                                                                            |
| `mode`      | `replace`, `preserve`, `append` or `prepend`.                                                                    |
| `stub-hash` | `sha256:<hex>` of the stub at the last apply. Entries written by `0.2.x` lack it until a run applies them again. |
| `merge`     | Merge strategy (`ini`, `list` or `text`) selected by the manifest `merge` map; absent when the name decides it.  |
| `ejected`   | `true` after `vendor/bin/scaffold eject <file> --yes`: no run writes the file until `reapply <file> --force`.    |
| `adopted`   | `true` for a `preserve` file that existed before scaffold tracked it.                                            |

The file is written atomically with `providers` and `files` sorted by key and a trailing newline, so diffs stay small.
Keys that scaffold does not know are kept. A lock with Git conflict markers is rejected with a message that names them;
resolve the conflict and run Composer again.

## Composer events

| Composer event            | Behavior                                                                                                                      |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `post-install-cmd`        | Applies every allowed and installed provider: every mode on every destination, ejected files skipped.                         |
| `post-update-cmd`         | Same as `post-install-cmd`.                                                                                                   |
| `post-create-project-cmd` | Same, unless `post-install-cmd` or `post-update-cmd` already ran in the same Composer process, so a template is applied once. |

There is no partial run: `append` and `prepend` files are re-evaluated on every event, so new provider entries reach
existing projects on the next `composer install` or `composer update`.

The plugin subscribes to these events as a Composer plugin, not as a script:

- `composer install --no-scripts` still scaffolds.
- `composer install --no-plugins` does not load the plugin
  (`The "php-forge/scaffold" plugin was not loaded as plugins are disabled.`); run `vendor/bin/scaffold sync`
  afterwards, or a later plain `composer install` catches up.
- With the plugin in `require-dev`, a fresh `composer install --no-dev`, such as a production build, does not install it
  and writes no scaffold file.

## Output and failure policy

A Composer run that writes a file or needs attention ends with one summary line on stdout, such as
`[scaffold] 2 files written, 7 unchanged, 1 conflict, 1 orphan, 1 warning.`; a run that changes nothing prints nothing.
Warnings and errors go to stderr, prefixed with `[scaffold]`.

The run fails (Composer exits with `1`) when:

- `scaffold-lock.json` cannot be read: invalid JSON or Git conflict markers. Nothing is applied.
- The manifest of an allowed and installed provider is invalid: unknown key, invalid mode or merge strategy, unsafe
  path, missing `copy` path. The message names the provider; nothing is applied.
- A file cannot be applied (unsafe path, unreadable stub, write failure). The other files are applied and recorded
  first, then the run fails with `[scaffold] 1 file failed while scaffolding; see the messages above.`

Warnings keep the run successful:

- An allowed provider that is not installed, or that produced no file mapping.
- A `replace` file that overwrote an untracked file with different content.
- A user-modified `replace` file that was skipped, with the `conflict` line when its stub changed (see
  [File Modes](modes.md#provider-updates-after-a-version-bump)).
- Orphaned files that no allowed provider ships anymore, listed with the `vendor/bin/scaffold prune` hint. Composer runs
  never delete them.

## Next steps

- 📖 [Readme](../README.md)
- 📦 [Creating Providers](providers.md)
- 🔀 [File Modes](modes.md)
- 🖥️ [Console Commands](console.md)
