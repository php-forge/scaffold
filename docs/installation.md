# Installation guide

## System requirements

- [PHP](https://www.php.net/downloads) `8.3` or higher.
- [Composer](https://getcomposer.org/download/) `2.3` or higher (the plugin requires `composer-plugin-api` `^2.3`).
- [Symfony Console](https://symfony.com/doc/current/components/console.html) `^7.4` or `^8.0`, installed as a
  dependency. Symfony Console 8 requires PHP 8.4, so a project on PHP 8.3 resolves 7.4.
- [php-forge/diff](https://github.com/php-forge/diff), installed as a dependency, which renders the unified diffs with
  Git's default algorithm (Myers with the indent heuristic).

The plugin is framework-agnostic: it works in any project managed by Composer.

## Install the plugin

Scaffolding is a development-time task, so require the plugin in `require-dev` and allow it to run:

```bash
composer config allow-plugins.php-forge/scaffold true
composer require --dev php-forge/scaffold:^0.3
```

The equivalent `composer.json` configuration is:

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

Without the `allow-plugins` entry, Composer asks whether to trust the plugin, and non-interactive runs such as CI fail.

## Authorize providers

Require the provider packages and list, in order, the ones allowed to write files into the project. Packages that are
not listed are ignored.

```bash
composer require --dev php-forge/baseline php-forge/coding-standard
```

```json
{
    "extra": {
        "scaffold": {
            "allowed-packages": ["php-forge/baseline", "php-forge/coding-standard"]
        }
    }
}
```

Run `composer install` or `composer update`. The plugin applies the files of every listed provider, writes
`scaffold-lock.json`, and ends with a summary line on stdout:

```text
[scaffold] 10 files written, 1 unchanged, 1 warning.
```

Warnings, such as an existing file that the first install overwrote, are printed on stderr above it; see
[File Modes](modes.md#replace). Review the result with `git diff`.

## Commit the lock file

`scaffold-lock.json` records which provider owns each file, how it was written and the hashes of the last apply. Commit
it with the scaffolded files, so every clone and CI job detects user-modified files and provider updates the same way:

```bash
git add scaffold-lock.json
```

## Composer flags

The plugin listens to Composer events as a plugin, not as a script:

| Command                          | Scaffolds?                                                               |
| -------------------------------- | ------------------------------------------------------------------------ |
| `composer install`               | Yes.                                                                     |
| `composer install --no-scripts`  | Yes: `--no-scripts` disables scripts, not plugin event subscribers.      |
| `composer install --no-plugins`  | No: the plugin is not loaded. Run `vendor/bin/scaffold sync` afterwards. |
| `composer install --no-dev`      | No, on a fresh install: the plugin in `require-dev` is not installed.    |
| Any command with `"auto": false` | No: run `vendor/bin/scaffold sync` when you want to apply the providers. |

## Manual mode with auto: false

To review every change to the shared files before it lands, disable the Composer hooks and apply the providers with the
console:

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

Composer runs then print
`[scaffold] Auto-scaffold disabled via "extra.scaffold.auto"; run "vendor/bin/scaffold" manually.` Bootstrap the project
with `sync`, which also creates `scaffold-lock.json`:

```text
$ vendor/bin/scaffold status
...
[scaffold] 10 files: 10 pending.
$ vendor/bin/scaffold sync --dry-run --exit-code
...
[scaffold] sync --dry-run: 10 files would change, 0 unchanged, 0 orphan, 0 errors.
$ vendor/bin/scaffold sync
[scaffold] sync: 10 files written, 0 unchanged.
```

Run `vendor/bin/scaffold sync` again after each provider update.

## Invoke the console

The plugin installs a standalone console application at `vendor/bin/scaffold`; it starts from Composer's autoloader and
needs no framework bootstrap:

```bash
vendor/bin/scaffold list        # available commands
vendor/bin/scaffold status      # state of every scaffolded file
vendor/bin/scaffold diff --all  # what applying the providers would change
```

See [Console Commands](console.md) for every command and option.

## Next steps

- 📖 [Readme](../README.md)
- ⚙️ [Configuration Reference](configuration.md)
- 📦 [Creating Providers](providers.md)
- 🔀 [File Modes](modes.md)
- 🖥️ [Console Commands](console.md)
