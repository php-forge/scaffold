# File modes

A provider manifest assigns a write mode to each destination through its `modes` map; a destination without a matching
pattern uses `replace`. Every mode runs on every `composer install`, `composer update` and `composer create-project`,
and on `vendor/bin/scaffold sync`. All modes are idempotent: a second run with the same provider and the same project
files writes nothing.

## Mode reference

| Mode       | Destination missing | Destination exists, not tracked yet                                                                                                                                                             | Destination tracked and unchanged since the last apply | Destination changed since the last apply                                                                         |
| ---------- | ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------- |
| `replace`  | Copies the stub.    | Overwrites it with the stub. When the content differed, prints `Overwrote untracked existing file "<file>"; review it with git diff or run "scaffold eject <file> --yes" to keep your version.` | Copies the current stub, so provider updates arrive.   | Skips it with `User-modified file skipped: "<file>".`; a changed provider stub makes it a `conflict`.            |
| `preserve` | Copies the stub.    | Keeps it and records it with `adopted: true`.                                                                                                                                                   | Keeps it.                                              | Keeps it.                                                                                                        |
| `append`   | Copies the stub.    | Adds the provider entries the file lacks, with the [merge strategy](#append) of the destination.                                                                                                | Adds the provider entries the file lacks.              | Adds the provider entries the file lacks; consumer lines are never removed, reordered or overridden.             |
| `prepend`  | Copies the stub.    | Inserts the stub block at the top unless the file already contains it.                                                                                                                          | Inserts the stub block unless the file contains it.    | Inserts the stub block unless the file contains it; a block moved elsewhere in the file still counts as present. |

Files ejected with `vendor/bin/scaffold eject <file> --yes` are never written by any mode until
`vendor/bin/scaffold reapply <file> --force` takes them back. `replace` and `preserve` copy the file permissions of the
stub; `append` and `prepend` keep the permissions of the existing file.

## Hash tracking

`scaffold-lock.json` records two hashes per file, both `sha256:<hex>` of the file bytes:

- `hash`: the destination as scaffold last left it. When the destination exists and scaffold did not write it (an
  adopted `preserve` file, an `append` file that already held every entry), it is the hash of the file at that moment.
- `stub-hash`: the provider source at the last apply. Comparing it with the current stub tells a provider change apart
  from a local edit.

How each mode uses them:

| Mode       | `hash` decides                                                                       | `stub-hash` decides                                                           |
| ---------- | ------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------- |
| `replace`  | Whether the file is user-modified (the disk hash differs), so the next run skips it. | Whether a skipped file is a `conflict`, and whether `status` says `outdated`. |
| `preserve` | Whether `status` reports `modified`.                                                 | Whether `status` and the run summary report `outdated`.                       |
| `append`   | Nothing during a run; the merge itself decides what to write.                        | Whether `prune` treats an orphan as provider-only content.                    |
| `prepend`  | Nothing during a run; the block search decides what to write.                        | Whether `prune` treats an orphan as provider-only content.                    |

A `replace` or `preserve` file recorded with `adopted: true` existed before scaffold tracked it, so scaffold never
treats it as pristine: if the provider later switches it to `replace`, it is skipped as user-modified until
`reapply <file> --force` or `sync --force`.

## replace

For files that the provider owns, such as linter configuration. Leave them unchanged to keep receiving updates; editing
one stops the updates for that file until you take the provider version back.

```json
{
    "copy": [{ "from": "metadata/.styleci.yml", "to": ".styleci.yml" }]
}
```

The first install overwrites an existing file that scaffold does not track yet, so the provider version wins on
adoption. Review the result with `git diff`; to keep your version, run `vendor/bin/scaffold eject <file> --yes`, restore
the file from Git, and commit both.

## preserve

For files that the provider seeds once and the project then owns, such as `ecs.php` and `rector.php` of
`php-forge/coding-standard`. Composer runs and `sync` never overwrite an existing `preserve` file; only
`vendor/bin/scaffold reapply <file> --force` does.

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

When a provider release changes a `preserve` stub, the run that installs it counts the file as `outdated` in its summary
line and records the new `stub-hash`; the file itself is not touched. `vendor/bin/scaffold diff <file>` shows the
provider version labelled `(preserve: applied only with reapply --force)`.

## append

A format-aware, idempotent merge for files that the provider and the project both extend, such as `.editorconfig` and
`.gitignore` of `php-forge/baseline`. The merge adds the provider entries missing from the destination and never
removes, reorders or overrides consumer content. Inserted lines use the line endings of the destination (CRLF when the
file contains any).

```json
{
    "copy": [
        { "from": "metadata/.editorconfig", "to": ".editorconfig" },
        { "from": "metadata/.gitignore", "to": ".gitignore" }
    ],
    "modes": {
        ".editorconfig": "append",
        ".gitignore": "append"
    }
}
```

The manifest `merge` map selects the strategy per destination (see [Creating Providers](providers.md#manifest-keys));
without a match, the basename of the destination decides:

| Strategy | Default for                                                                                                                                                       |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ini`    | `.editorconfig` and every `*.ini` file.                                                                                                                           |
| `list`   | `.codespellignore`, `.dockerignore`, `.eslintignore`, `.gitattributes`, `.gitignore`, `.markdownlintignore`, `.npmignore`, `.prettierignore`, `.stylelintignore`. |
| `text`   | Every other file.                                                                                                                                                 |

### ini

- Keys before the first section (such as `root = true`) are added after the last such key of the destination, or at the
  top.
- A section is identified by its header text: `[*.js]` and `[*.{cjs,js,mjs}]` are different sections.
- A key missing from a section that the destination has is added after the last key of the first occurrence of that
  section. Key names are compared case-insensitively.
- An existing key is never duplicated or changed: the consumer value wins, `unset` included.
- A missing section is appended at the end as a whole block, with the comment lines directly above its header.
- `#` and `;` start comments.

Provider `.editorconfig` merged into a destination that sets `indent_size = 2` for `[*]`:

```ini
root = true

[*]
indent_size = 2
charset = utf-8
end_of_line = lf
insert_final_newline = true
indent_style = space
trim_trailing_whitespace = true

[*.js]
indent_size = 2
```

### list

- Entries are the non-blank lines that do not start with `#`, compared after trimming and byte for byte: `vendor` and
  `/vendor` are different entries. Comments and blank lines never count as entries.
- The provider file is read as blocks: comment lines followed by entries, separated by a blank line or by a comment that
  follows an entry.
- When the destination contains the first comment line of a block, the missing entries of that block are added after the
  last entry under that comment. Otherwise the block is appended at the end with its comments.

> **Note:** ignore files are order-sensitive: the last matching rule wins. An entry appended at the end lands after
> every consumer line, so it overrides a consumer negation that targets it. For example, a provider block `*.log`
> appended after a consumer `!keep.log` ignores `keep.log` again. Move the negation below the appended block, or ask the
> provider to ship the negation.

### text

- Every non-blank provider line missing from the destination (compared after trimming trailing whitespace) is appended
  at the end, after a blank line, in provider order.
- Lines are compared whole, so `text` does not understand keys: a provider `APP_ENV=dev` is added below a consumer
  `APP_ENV=prod`.

Do not use `append` for structured documents (JSON, YAML, TOML, XML); use `replace` or `preserve` for those.

## prepend

Inserts the stub at the top of the destination, for example a license or "generated file" notice in a text file. The
block is terminated with the end-of-line sequence of the destination. Before writing, the mode searches the destination
for the stub lines as one contiguous run (line endings normalised); when it finds them anywhere in the file, it writes
nothing, so repeated runs never duplicate the block.

```json
{
    "copy": [{ "from": "stubs/NOTICE.txt", "to": "NOTICE" }],
    "modes": { "NOTICE": "prepend" }
}
```

Do not prepend to files whose first line is significant, such as PHP files that start with `<?php`.

## reapply and --force

`vendor/bin/scaffold reapply` applies the stubs of tracked files outside Composer:

- Without `--force`, `replace` files are rewritten from the stub unless they are user-modified or adopted, `append` and
  `prepend` files are merged as on a Composer run, and existing `preserve` files, ejected files and orphans are skipped.
- With `--force`, every matching file except orphans is rewritten from its stub, user-modified, adopted, `preserve`,
  `append`, `prepend` and ejected files included, and the `ejected` and `adopted` flags are cleared. Consumer additions
  to `append` and `prepend` files are lost.

```bash
vendor/bin/scaffold reapply .styleci.yml --force
```

## Provider updates after a version bump

After `composer update` installs a new provider release:

- `replace` files you did not edit receive the new stub.
- `append` and `prepend` files receive the new provider entries.
- `preserve` files stay as they are; the summary line counts changed stubs as `outdated`.
- A `replace` file you edited whose stub changed is a `conflict`. The run keeps your version and prints, on every run
  until you decide:

```text
[scaffold] User-modified file skipped: ".styleci.yml".
[scaffold] 1 file has provider updates you have not taken (conflict); run "vendor/bin/scaffold diff --all".
```

Review the change with `vendor/bin/scaffold diff <file>`, then either take the provider version with
`vendor/bin/scaffold reapply <file> --force` and re-apply your edits, or keep your version for good with
`vendor/bin/scaffold eject <file> --yes`.

## Next steps

- 📖 [Readme](../README.md)
- 🖥️ [Console Commands](console.md)
- ⚙️ [Configuration Reference](configuration.md)
- 📦 [Creating Providers](providers.md)
