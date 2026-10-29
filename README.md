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
        <img src="https://img.shields.io/github/actions/workflow/status/php-forge/scaffold/build.yml?style=for-the-badge&label=Testo&logo=github" alt="Testo">
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
composer require --dev php-forge/scaffold:^0.3
```

Require the providers and list, in order, the ones allowed to write files into the project:

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

Run `composer install`. The plugin copies the provider files, merges the shared ones, writes `scaffold-lock.json` and
prints one summary line:

```text
[scaffold] 10 files written, 1 unchanged, 1 warning.
```

Review the result with `git diff` and commit the files together with `scaffold-lock.json`.

## Configuration

| Setting                           | Default | Effect                                                                                            |
| --------------------------------- | ------- | ------------------------------------------------------------------------------------------------- |
| `extra.scaffold.allowed-packages` | `[]`    | Providers allowed to write files, in order; the later provider wins when two ship the same file.  |
| `extra.scaffold.auto`             | `true`  | `false` disables the Composer hooks; apply the providers with `vendor/bin/scaffold sync` instead. |

Providers choose a mode per file: `replace` (provider-owned, skipped once you edit it), `preserve` (seeded once, then
yours), `append` (format-aware merge for `.editorconfig`, `.gitignore` and similar files) or `prepend`. The lock records
the owner, the mode and two hashes per file:

```json
{
    "providers": {
        "php-forge/baseline": {
            "version": "0.2.0",
            "path": "vendor/php-forge/baseline"
        }
    },
    "files": {
        ".styleci.yml": {
            "hash": "sha256:825f8c744ffbd2bc2369712e7192b8694129a8ecbc3fd91dc9300fccecf827bd",
            "provider": "php-forge/baseline",
            "source": "metadata/.styleci.yml",
            "mode": "replace",
            "stub-hash": "sha256:825f8c744ffbd2bc2369712e7192b8694129a8ecbc3fd91dc9300fccecf827bd"
        }
    }
}
```

`hash` is the file as scaffold last left it and `stub-hash` the provider source at that moment, so scaffold can tell
your edits apart from provider updates.

## Console commands

The plugin installs a standalone console application at `vendor/bin/scaffold`. Every command finds the project root by
walking up to the nearest `composer.json`; the global `--working-dir` (`-d`) option starts the search elsewhere.

| Command                                                                    | What it does                                                                                                           |
| -------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `status [--format=table\|json] [--exit-code]`                              | Shows each file as `synced`, `pending`, `outdated`, `modified`, `conflict`, `missing`, `orphan`, `ejected` or `error`. |
| `diff <file>\|--all [--exit-code]`                                         | Shows what applying the providers would change, as a unified diff from the current file.                               |
| `reapply [<file>] [--provider=<name>] [--force] [--dry-run] [--exit-code]` | Applies the stubs of tracked files again; `--force` also takes user-modified, `preserve` and ejected files.            |
| `eject <file> [--yes]`                                                     | Marks a file as yours; no run writes it again until `reapply <file> --force`.                                          |
| `prune [--force] [--delete-modified] [--exit-code]`                        | Lists files no allowed provider ships anymore; `--force` deletes the unmodified ones and drops the entries.            |
| `sync [--dry-run] [--exit-code] [--force]`                                 | Applies every allowed provider outside Composer, for `auto: false`, `--no-plugins` runs and bots.                      |
| `providers`                                                                | Lists the providers recorded in `scaffold-lock.json` with their file counts.                                           |

Typical workflow after `composer install` or `composer update`:

```bash
vendor/bin/scaffold status                         # what needs attention
vendor/bin/scaffold diff --all                     # what applying the providers would change
vendor/bin/scaffold reapply .styleci.yml --force   # take the provider version of a file you edited
vendor/bin/scaffold eject .styleci.yml --yes       # or keep yours for good
vendor/bin/scaffold prune                          # review files the providers no longer ship
```

Gate CI on drift between the committed files and the providers:

```bash
vendor/bin/scaffold status --exit-code
```

Upgrading from `yii2-extensions/scaffold` `0.2`? Read [UPGRADE.md](UPGRADE.md).

## Documentation

- 📚 [Installation Guide](docs/installation.md)
- ⚙️ [Configuration Reference](docs/configuration.md)
- 📦 [Creating Providers](docs/providers.md)
- 🔀 [File Modes](docs/modes.md)
- 🖥️ [Console Commands](docs/console.md)
- 🧪 [Testing Guide](docs/testing.md)
- ⬆️ [Upgrade Guide](UPGRADE.md)

## Package information

[![PHP](https://img.shields.io/badge/%3E%3D8.3-777BB4.svg?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/releases/8.3/en.php)
[![Latest Stable Version](https://img.shields.io/packagist/v/php-forge/scaffold.svg?style=for-the-badge&logo=packagist&logoColor=white&label=Stable)](https://packagist.org/packages/php-forge/scaffold)
[![Total Downloads](https://img.shields.io/packagist/dt/php-forge/scaffold.svg?style=for-the-badge&logo=composer&logoColor=white&label=Downloads)](https://packagist.org/packages/php-forge/scaffold)

## Project status

[![Codecov](https://img.shields.io/codecov/c/github/php-forge/scaffold.svg?style=for-the-badge&logo=codecov&logoColor=white&label=Coverage)](https://codecov.io/github/php-forge/scaffold)
[![PHPStan Level Max](https://img.shields.io/badge/PHPStan-Level%20Max-4F5D95.svg?style=for-the-badge&logo=github&logoColor=white)](https://github.com/php-forge/scaffold/actions/workflows/static.yml)
[![Quality](https://img.shields.io/github/actions/workflow/status/php-forge/scaffold/quality.yml?style=for-the-badge&label=Quality&logo=github)](https://github.com/php-forge/scaffold/actions/workflows/quality.yml)
[![StyleCI](https://img.shields.io/badge/StyleCI-Passed-44CC11.svg?style=for-the-badge&logo=github&logoColor=white)](https://github.styleci.io/repos/1212894073?branch=main)

## Built with AI

[![Built with Claude Code](https://img.shields.io/badge/Built%20with-Claude%20Code-D97757.svg?style=for-the-badge&logo=claude&logoColor=white)](https://claude.com/claude-code)
[![Reviewed by Codex](https://img.shields.io/badge/Reviewed%20by-Codex-10A37F.svg?style=for-the-badge&logo=openaigym&logoColor=white)](https://openai.com/codex)
[![Reviewed by CodeRabbit](https://img.shields.io/badge/Reviewed%20by-CodeRabbit-FF570A.svg?style=for-the-badge&logo=coderabbit&logoColor=white)](https://www.coderabbit.ai)

## Our social networks

[![Follow on X](https://img.shields.io/badge/-Follow%20on%20X-1DA1F2.svg?style=for-the-badge&logo=x&logoColor=white&labelColor=000000)](https://x.com/Terabytesoftw)
[![Follow on Facebook](https://img.shields.io/badge/-Follow%20on%20Facebook-1877F2.svg?style=for-the-badge&logo=facebook&logoColor=white&labelColor=000000)](https://www.facebook.com/wilmer.arambula.9)

## License

[![License](https://img.shields.io/badge/License-BSD--3--Clause-brightgreen.svg?style=for-the-badge&logo=opensourceinitiative&logoColor=white&labelColor=555555)](LICENSE)
