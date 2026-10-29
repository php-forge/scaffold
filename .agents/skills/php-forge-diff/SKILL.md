---
name: php-forge-diff
description: Integrate or troubleshoot php-forge/diff line edit scripts and unified diff rendering in PHP applications. Use for LineDiffer, DifferInterface, or UnifiedDiffRenderer; exclude applying patches and unrelated Git operations.
license: BSD-3-Clause
metadata:
  source: https://github.com/php-forge/diff
---

# Use php-forge/diff

Inspect the installed revision and its `composer.json`, source and guides before choosing an API. For a dependency,
resolve Composer's installed path and reference; a path checkout can contain uncommitted changes. Compare the lock file,
which may describe a different installation. Use documentation matching the installed files and report a gap if it
cannot be found. PHP 8.3 or later is required by the current package.

## Choose the result

Use `(new PHPForge\Diff\LineDiffer())->diff($old, $new)` for an ordered list of `DiffLine` objects with
`DiffOp::Context`, `Remove`, or `Add`. It splits both strings literally on `"\n"`: an empty string is one empty line and
a trailing newline produces an empty final line. Remove `Add` entries to reconstruct the old input, or `Remove` entries
for the new input, joining the remaining `line` values with `"\n"`. It does not normalize line endings.

Use `(new PHPForge\Diff\UnifiedDiffRenderer())->render($old, $new, $fromLabel, $toLabel, $context)` for unified text;
`$context` is optional and defaults to `3`. It normalizes CRLF and CR to LF, returns an empty string for normalized
equal texts and otherwise returns a patch without a trailing newline or Git's `diff --git` and `index` headers. Negative
context is treated as zero. Missing final newlines are represented by `\ No newline at end of file`. Labels are supplied
by the caller; this renderer does not read files, apply patches, or escape labels for untrusted presentation.

A custom `DifferInterface` can be injected into the renderer. Its script must reconstruct both inputs in order. The
renderer takes the rendered lines from the texts by operation position and does not validate `DiffLine::line`: a script
whose operations do not consume both inputs exactly throws `LogicException`, while a balanced script with wrong
operations renders an incorrect diff without any error. Test a non-empty change as well as empty inputs because the
renderer handles empty sides separately. Do not swallow `LogicException` as an empty diff.

The default differ follows Git's default xdiff pipeline and indent heuristic, but lacks Git's cost limit. Its edit
script is not guaranteed shortest or identical to Git on expensive inputs. Do not promise binary diff support or exact
byte preservation after renderer line-ending normalization.

## Verify

Use the application's existing tests and the package's `docs/examples.md`, `docs/algorithms.md` and `docs/testing.md` at
the matching revision. Assert both reconstructed sides, unchanged inputs, empty sides, trailing-newline differences,
line-ending normalization, requested context and custom-differ failure as relevant. If comparing with Git, disable user
and system Git configuration and document the chosen options. Record installation, client discovery, native skill
loading, actual task behavior and test results separately; reading this file alone proves no activation.
