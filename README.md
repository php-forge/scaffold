<!-- markdownlint-disable MD041 -->
<p align="center">
    <a href="https://github.com/php-forge/scaffold" target="_blank">
      <img src="https://avatars.githubusercontent.com/u/103309199?s=400&u=ca3561c692f53ed7eb290d3bb226a2828741606f&v=4" width="30%" alt="PHP Forge">
    </a>
    <h1 align="center">Scaffold</h1>
    <br>
</p>
<!-- markdownlint-enable MD041 -->

<p align="center">
    <a href="https://github.com/php-forge/scaffold/actions/workflows/build.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/php-forge/scaffold/build.yml?style=for-the-badge&label=PHPUnit&logo=github" alt="PHPUnit">
    </a>
    <a href="https://dashboard.stryker-mutator.io/reports/github.com/php-forge/scaffold/main" target="_blank">
        <img src="https://img.shields.io/endpoint?style=for-the-badge&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fphp-forge%2Fscaffold%2Fmain" alt="Mutation Testing">
    </a>
    <a href="https://github.com/php-forge/scaffold/actions/workflows/static.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/php-forge/scaffold/static.yml?style=for-the-badge&label=PHPStan&logo=github" alt="PHPStan">
    </a>
    <a href="https://github.com/php-forge/scaffold/actions/workflows/security.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/php-forge/scaffold/security.yml?style=for-the-badge&label=Security&logo=github" alt="Security">
    </a>
</p>

<p align="center">
    <strong>Declarative multi-layer file scaffolding for PHP projects</strong><br>
    <em>Distribute project files through Composer providers and manage them with a standalone Console CLI.</em>
</p>

## Features

<picture>
    <source media="(max-width: 767px)" srcset="./docs/svgs/features-mobile.svg">
    <img src="./docs/svgs/features.svg" alt="Feature Overview" style="width: 100%;">
</picture>

## Installation

```bash
composer config allow-plugins.php-forge/scaffold true
composer require php-forge/scaffold:^0.3
```

Install your scaffold providers, then declare which are permitted to write files into your project:

```json
{
    "extra": {
        "scaffold": {
            "allowed-packages": [
                "php-forge/baseline",
                "php-forge/coding-standard"
            ]
        }
    }
}
```

Run `composer install` to trigger the scaffold process. Commit `scaffold-lock.json` to version control.

To opt out of auto-trigger and manage updates manually via `vendor/bin/scaffold`, set `auto: false` (see
[Configuration Reference](docs/configuration.md#auto)):

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

## Configuration

Scaffold configuration for a project with `php-forge/baseline` and `php-forge/coding-standard` already installed:

```json
{
    "require": {
        "php-forge/scaffold": "^0.3"
    },
    "config": {
        "allow-plugins": {
            "php-forge/scaffold": true
        }
    },
    "extra": {
        "scaffold": {
            "allowed-packages": [
                "php-forge/baseline",
                "php-forge/coding-standard"
            ]
        }
    }
}
```

## Console commands

After `composer install`, the plugin ships a standalone Symfony Console CLI at `vendor/bin/scaffold` that works in any
PHP project (Yii2, Yii3, Laravel, Symfony, plain PHP).

| Command                                                            | What it does                                                                                                                                     |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| `vendor/bin/scaffold status`                                       | Prints a table comparing every tracked file's on-disk hash to the one recorded in `scaffold-lock.json` (`synced` / `modified` / `missing`).      |
| `vendor/bin/scaffold providers`                                    | Lists every provider recorded in `scaffold-lock.json` and how many files each one contributed.                                                   |
| `vendor/bin/scaffold diff <file>`                                  | Shows a line-by-line diff between the provider stub and the current on-disk file.                                                                |
| `vendor/bin/scaffold reapply [file] [--force] [--provider=<name>]` | Re-copies stubs from `vendor/` to the project, updating lock hashes on success. Without `--force`, user-modified files are reported and skipped. |
| `vendor/bin/scaffold eject <file> [--yes]`                         | Removes a file entry from `scaffold-lock.json` without deleting the file from disk. Without `--yes` only previews the change.                    |

Typical post-install workflow:

```bash
vendor/bin/scaffold status                                      # what changed
vendor/bin/scaffold diff config/params.php                      # review one file
vendor/bin/scaffold reapply config/params.php --force           # accept stub version

# or
vendor/bin/scaffold eject config/params.php --yes               # keep yours, stop tracking
```

See [`docs/console.md`](docs/console.md) for the full reference.

## Documentation

- 📚 [Installation Guide](docs/installation.md)
- ⚙️ [Configuration Reference](docs/configuration.md)
- 📦 [Creating Providers](docs/providers.md)
- 🔀 [File Modes](docs/modes.md)
- 🖥️ [Console Commands](docs/console.md)
- 🧪 [Testing Guide](docs/testing.md)

## Package information

[![PHP](https://img.shields.io/badge/%3E%3D8.3-777BB4.svg?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/releases/8.3/en.php)
[![Latest Stable Version](https://img.shields.io/packagist/v/php-forge/scaffold.svg?style=for-the-badge&logo=packagist&logoColor=white&label=Stable)](https://packagist.org/packages/php-forge/scaffold)
[![Total Downloads](https://img.shields.io/packagist/dt/php-forge/scaffold.svg?style=for-the-badge&logo=composer&logoColor=white&label=Downloads)](https://packagist.org/packages/php-forge/scaffold)

## Project status

[![PHPStan Level Max](https://img.shields.io/badge/PHPStan-Level%20Max-4F5D95.svg?style=for-the-badge&logo=github&logoColor=white)](https://github.com/php-forge/scaffold/actions/workflows/static.yml)
[![Quality](https://img.shields.io/github/actions/workflow/status/php-forge/scaffold/quality.yml?style=for-the-badge&label=Quality&logo=github)](https://github.com/php-forge/scaffold/actions/workflows/quality.yml)
[![StyleCI](https://img.shields.io/badge/StyleCI-Passed-44CC11.svg?style=for-the-badge&logo=github&logoColor=white)](https://github.styleci.io/repos/1212894073?branch=main)

## Our social networks

[![Follow on X](https://img.shields.io/badge/-Follow%20on%20X-1DA1F2.svg?style=for-the-badge&logo=x&logoColor=white&labelColor=000000)](https://x.com/Terabytesoftw)
[![Follow on Facebook](https://img.shields.io/badge/-Follow%20on%20Facebook-1877F2.svg?style=for-the-badge&logo=facebook&logoColor=white&labelColor=000000)](https://www.facebook.com/wilmer.arambula.9)

## License

[![License](https://img.shields.io/badge/License-BSD--3--Clause-brightgreen.svg?style=for-the-badge&logo=opensourceinitiative&logoColor=white&labelColor=555555)](LICENSE)
