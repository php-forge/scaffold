# Testing

This package provides a consistent set of [Composer](https://getcomposer.org/) scripts for local validation. Install the
development dependencies first:

```bash
composer install
```

Tool references:

- [Actionlint](https://github.com/rhysd/actionlint) for GitHub Actions workflow linting.
- [Codespell](https://github.com/codespell-project/codespell) for spelling checks.
- [Composer Require Checker](https://github.com/maglnet/ComposerRequireChecker) for dependency definition checks.
- [Easy Coding Standard (ECS)](https://github.com/easy-coding-standard/easy-coding-standard) for coding standards.
- [EditorConfig Checker](https://github.com/editorconfig-checker/editorconfig-checker) for EditorConfig compliance.
- [Gitleaks](https://github.com/gitleaks/gitleaks) for secret scanning.
- [Infection](https://infection.github.io/) for mutation testing.
- [Markdownlint-cli2](https://github.com/DavidAnson/markdownlint-cli2) for Markdown linting.
- [PHPStan](https://phpstan.org/) for static analysis.
- [Prettier](https://prettier.io/) for Markdown and YAML formatting checks.
- [Rector](https://github.com/rectorphp/rector) for automated refactoring.
- [Testo](https://github.com/php-testo/testo) for unit, functional and real-Composer tests.
- [Yamllint](https://github.com/adrienverge/yamllint) for YAML linting.
- [Zizmor](https://github.com/zizmorcore/zizmor) for GitHub Actions security audits.

The Testo configuration lives in `testo.php` and defines two suites:

- `Unit` reads `tests/` without `tests/Composer`, `tests/Fixtures`, `tests/Provider` and `tests/Support`. It holds the
  unit, command and functional tests, which run in the current process.
- `Composer` reads `tests/Composer`. It runs a real Composer binary in subprocesses against temporary projects (see
  [Real Composer tests](#real-composer-tests)).

Both suites fail a test on any PHP warning, notice or deprecation, and on a test without assertions
(`tests/Support/StrictTestPlugin.php`). Filesystem failures are exercised against the real filesystem (a directory where
a file is expected, a regular file as a parent directory, a missing source, `chmod 0000`), without function mocks; the
`chmod` cases are skipped on Windows and when running as root.

## Automated refactoring (Rector)

Run Rector to apply automated code refactoring. This command modifies files.

```bash
composer rector
```

Preview the refactoring without modifying files.

```bash
composer rector -- --dry-run
```

## Coding standards (ECS)

Check coding standards without modifying files.

```bash
vendor/bin/ecs check
```

Apply coding standard fixes. Review the changes before keeping them.

```bash
composer ecs
```

## Dependency definition check

Verify that runtime dependencies are correctly declared in `composer.json`.

```bash
composer check-dependencies
```

## Mutation testing (Infection)

Run mutation testing with PHPStan static analysis enabled. Infection runs the `Unit` suite through
`testo/bridge-infection`. This is the command CI runs.

```bash
composer mutation-static
```

Run mutation testing without static analysis.

```bash
composer mutation
```

Both runs reach the required score without ignored mutants. Thresholds, thread count and memory options live in
`infection.json5`; the package requires a 100 % mutation score indicator (MSI) and covered MSI. The `Composer` suite is
left out because its subprocesses install a snapshot of the working tree instead of running the mutated code.

## Repository quality and security checks

The `quality` workflow runs Actionlint and uses the repository configurations for Codespell, EditorConfig Checker,
Markdownlint-cli2, Prettier and Yamllint. The `security` workflow runs Zizmor with its pedantic persona and Gitleaks
with the repository configuration. These checks are not exposed as Composer scripts in this package; run the individual
tools directly with the checked-in configuration when reproducing a hosted check locally.

Report hosted workflow execution separately from local commands. Keep generated reports in ignored `internal/` or
`runtime/` paths.

## Static analysis (PHPStan)

Run static analysis at level max with the strict checks from `phpstan.neon`. The Composer script removes the PHP memory
limit.

```bash
composer static
```

## Tests (Testo)

Run both suites.

```bash
composer tests
```

Run only the `Unit` suite.

```bash
composer tests-unit
```

CI runs the suites on PHP 8.3, 8.4 and 8.5, on Ubuntu and Windows, and uploads the coverage to Codecov.

### Real Composer tests

The `Composer` suite covers the plugin lifecycle and the provider integration that an in-process test cannot reproduce:
`composer install`, `update` and `create-project` against a snapshot of this working tree and a provider fixture,
offline. The harness uses the `COMPOSER_BINARY` environment variable when set; otherwise it looks for `composer.phar`
and then `composer` on `PATH`. When none is available, every test of the suite is skipped.

Run only this suite:

```bash
composer tests -- --suite=Composer
```

Run it with a specific Composer PHAR:

```bash
COMPOSER_BINARY=/path/to/composer.phar composer tests -- --suite=Composer
```

### Running one test

Filter the tests by class or method name (the match is case-sensitive), and print a single JSON summary with the failed
tests instead of the terminal report.

```bash
vendor/bin/testo --json --filter=ScaffolderTest
```

### Code coverage

Collect coverage with PCOV or Xdebug enabled and write a Clover XML report.

```bash
composer tests-unit -- --coverage-clover=runtime/clover.xml
```

## Passing extra arguments

Composer scripts forward additional arguments after `--`.

Run PHPStan with debug output.

```bash
composer static -- --debug
```

## Next steps

- 📖 [Readme](../README.md)
- 🔀 [File Modes](modes.md)
- ⚙️ [Configuration Reference](configuration.md)
- 📦 [Creating Providers](providers.md)
- 🖥️ [Console Commands](console.md)
