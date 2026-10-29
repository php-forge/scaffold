# Creating providers

A scaffold provider is a Composer package that declares which of its files scaffold copies into the projects that
install it and allow it in `extra.scaffold.allowed-packages`. No special package type is needed: `php-forge/baseline`
and `php-forge/coding-standard` are plain `library` packages.

## Manifest keys

| Key       | Required | Value                                                                                                                                                                                       |
| --------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `copy`    | Yes      | Non-empty list of entries. A string copies the path to the same place in the project; `{"from": ..., "to": ...}` maps a provider path to a project path. A directory is walked recursively. |
| `exclude` | No       | List of glob patterns matched against provider-relative source paths found while walking a `copy` directory.                                                                                |
| `modes`   | No       | Object of destination path or glob pattern to [file mode](modes.md): `replace` (default), `preserve`, `append` or `prepend`.                                                                |
| `merge`   | No       | Object of destination path or glob pattern to [append strategy](modes.md#append): `ini`, `list` or `text`.                                                                                  |

Rules:

- Any other key is rejected, so a typo such as `mode` fails the Composer run instead of silently changing a mode:
  `Manifest contains unknown key "mode". Allowed keys: copy, exclude, merge, modes.`
- Every path and pattern must be relative and must not contain `..` segments; absolute paths, `\` prefixes and Windows
  drive letters are rejected. A path listed in `copy` must exist in the provider.
- In `modes` and `merge`, an exact destination wins over patterns; patterns are tried in declaration order and the first
  match wins.
- `exclude` applies only to the files found while walking a `copy` directory; a file listed explicitly in `copy` is
  always copied. `exclude` matches source paths, while `modes` and `merge` match destination paths; the two differ when
  `from` and `to` differ.
- Within one provider, a later `copy` entry that produces the same destination replaces the earlier one. Across
  providers, the provider listed later in the consumer's `allowed-packages` wins.

## External manifest

Point `extra.scaffold.manifest` at a JSON file inside the provider. `php-forge/baseline` declares:

```json
{
    "name": "php-forge/baseline",
    "type": "library",
    "extra": {
        "scaffold": {
            "manifest": "scaffold.json"
        }
    }
}
```

and ships this `scaffold.json`:

```json
{
    "copy": [
        { "from": "metadata/.editorconfig", "to": ".editorconfig" },
        { "from": "metadata/gitattributes", "to": ".gitattributes" },
        { "from": "metadata/.gitignore", "to": ".gitignore" },
        { "from": "metadata/.styleci.yml", "to": ".styleci.yml" },
        {
            "from": "metadata/composer-require-checker.json",
            "to": "composer-require-checker.json"
        },
        { "from": "metadata/.github/linters", "to": ".github/linters" }
    ],
    "modes": {
        ".editorconfig": "append",
        ".gitignore": "append",
        "composer-require-checker.json": "preserve"
    }
}
```

The provider keeps its templates under `metadata/`, so its own `.gitignore` and `.editorconfig` never leak into
consumers. The `.github/linters` directory entry copies every file below it with the default `replace` mode.

The manifest path is resolved inside the provider's install directory; it must be relative and must not contain `..`. An
external manifest may contain only the four manifest keys.

## Inline manifest

The manifest keys can also sit directly under `extra.scaffold` of the provider's `composer.json`. A package that is both
a provider and a consumer keeps its consumer keys (`allowed-packages`, `auto`) in the same block; they apply only when
the package is the root project. `php-forge/coding-standard` combines an external manifest with its own consumer
configuration:

```json
{
    "name": "php-forge/coding-standard",
    "extra": {
        "scaffold": {
            "manifest": "scaffold.json",
            "allowed-packages": ["php-forge/baseline"]
        }
    }
}
```

Its `scaffold.json` seeds two files that the consumer owns afterwards:

```json
{
    "copy": [
        { "from": "src/config/ecs.php", "to": "ecs.php" },
        { "from": "src/config/rector.php", "to": "rector.php" }
    ],
    "modes": {
        "ecs.php": "preserve",
        "rector.php": "preserve"
    }
}
```

An inline manifest with a `merge` override: `.env.dist` would default to `text`, which compares whole lines; `ini`
compares keys, so a consumer `APP_ENV=prod` is kept and no second `APP_ENV` line is added.

```json
{
    "extra": {
        "scaffold": {
            "copy": [{ "from": "stubs/env.dist", "to": ".env.dist" }],
            "modes": { ".env.dist": "append" },
            "merge": { ".env.dist": "ini" }
        }
    }
}
```

## Glob syntax

Patterns in `exclude`, `modes` and `merge` match the whole forward-slash path:

| Token   | Matches                                                                                  |
| ------- | ---------------------------------------------------------------------------------------- |
| `*`     | Any sequence of characters except `/`.                                                   |
| `**`    | Any sequence of characters, `/` included.                                                |
| `**/`   | Zero or more directory levels, so `**/.gitignore` also matches the root `.gitignore`.    |
| `?`     | One character except `/`.                                                                |
| `{a,b}` | One of the comma-separated alternatives (one level; braces without a comma are literal). |
| other   | The character itself, byte for byte.                                                     |

## Default excludes

These patterns are always skipped while walking a `copy` directory, so a provider never ships its own development files.
They are matched against the provider-relative source path. The generated API reference lists the exact current
[`DefaultExcludes::PATTERNS`](https://yii2-framework.github.io/docs/extensions/scaffold/reference/manifest/defaultexcludes/#const-PATTERNS);
the guide does not duplicate that implementation-owned list.

Patterns match the whole path from the provider root: `.gitignore` excludes the root `.gitignore`, not
`metadata/.gitignore`. To ship a file that matches a default exclude, such as `runtime/.gitignore`, list it explicitly
in `copy`.

## Security model

- **Allowlist**: only packages listed in the consumer's `allowed-packages` are read; any other installed package is
  ignored. The console commands report lock entries of providers that are no longer allowed and never read from or write
  to them.
- **Paths**: manifest paths and patterns must be relative without `..`. Before each write, the source must resolve
  inside the provider root and the destination inside the project root, also through symbolic links, a dangling symbolic
  link included. `scaffold-lock.json` is never a valid destination.
- **Failures are loud**: an invalid manifest of an allowed provider fails the Composer run before any file is written;
  see [Output and failure policy](configuration.md#output-and-failure-policy).

## Test a provider locally

Install the provider into a scratch project through a path repository and run the inspection commands:

```json
{
    "repositories": [{ "type": "path", "url": "../baseline", "options": { "symlink": false } }],
    "require-dev": {
        "php-forge/baseline": "@dev",
        "php-forge/scaffold": "^0.3"
    },
    "config": {
        "allow-plugins": { "php-forge/scaffold": true }
    },
    "extra": {
        "scaffold": { "allowed-packages": ["php-forge/baseline"] }
    }
}
```

```bash
composer install
vendor/bin/scaffold status
vendor/bin/scaffold diff --all
```

## Next steps

- 📖 [Readme](../README.md)
- 🔀 [File Modes](modes.md)
- 🖥️ [Console Commands](console.md)
