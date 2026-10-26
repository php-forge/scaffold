# Installation guide

## System requirements

- [PHP](https://www.php.net/downloads) `8.3` or higher.
- [Composer](https://getcomposer.org/download/) `2.9` or higher.

The plugin is framework-agnostic. It works in Yii2, Yii3, Laravel, Symfony, Slim, Mezzio, or plain PHP projects as long
as they use Composer.

## Installation

Allow the Composer plugin, then install it:

```bash
composer config allow-plugins.php-forge/scaffold true
composer require php-forge/scaffold:^0.3
```

Alternatively, add the permission to your `composer.json` before running `composer require`:

```json
{
    "config": {
        "allow-plugins": {
            "php-forge/scaffold": true
        }
    }
}
```

## Authorize scaffold providers

Install the scaffold provider packages required by your project, then declare which are permitted to write files under
`extra.scaffold.allowed-packages`. The plugin ignores all providers that are not listed here.

```json
{
    "extra": {
        "scaffold": {
            "allowed-packages": ["php-forge/baseline", "php-forge/coding-standard"]
        }
    }
}
```

Run `composer install` or `composer update` to trigger the scaffold process. The plugin applies all file mappings
declared by the listed providers and writes `scaffold-lock.json`.

## Commit the lockfile

`scaffold-lock.json` records the hash of every file written by the scaffold process. Commit it to version control
alongside `composer.lock` so that collaborators and CI can detect user-modified files and reproduce the exact scaffold
state.

```bash
git add scaffold-lock.json
git commit -m "chore: add scaffold-lock.json"
```

## Invoke the console CLI

After `composer install`, the Symfony Console CLI is available at `vendor/bin/scaffold`:

```bash
vendor/bin/scaffold list                      # discover available commands
vendor/bin/scaffold status                    # what changed since last scaffold?
vendor/bin/scaffold providers                 # list providers + file counts
vendor/bin/scaffold diff config/params.php    # review changes on a single file
vendor/bin/scaffold reapply --force           # replay stubs, overwriting user edits
vendor/bin/scaffold eject config/params.php --yes   # detach a file from the lock
```

No framework bootstrap is required; the binary starts directly from Composer's autoloader.

## Next steps

- 📖 [Readme](../README.md)
- ⚙️ [Configuration Reference](configuration.md)
- 📦 [Creating Providers](providers.md)
- 🔀 [File Modes](modes.md)
- 🖥️ [Console Commands](console.md)
