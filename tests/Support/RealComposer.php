<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Testo\Core\Exception\SkipTest;

use function array_keys;
use function array_shift;
use function copy;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function getenv;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function json_decode;
use function mkdir;
use function preg_match;
use function proc_close;
use function proc_open;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Runs the real `composer` binary offline against temporary projects that install a snapshot of this working tree
 * from path repositories.
 */
final class RealComposer
{
    /**
     * Version under which the snapshot of this working tree is installed.
     */
    public const string PLUGIN_VERSION = '0.3.0';
    /**
     * Entries of this working tree that make up the installed plugin package.
     */
    private const array PLUGIN_ENTRIES = ['bin', 'composer.json', 'src'];

    /**
     * Returns the repositories of a consumer project: Packagist disabled, the plugin snapshot at `$plugin`, the
     * provider fixture release `$providerVersion` (`1.0.0` or `2.0.0`), and the plugin runtime dependencies from this
     * `vendor/`.
     *
     * @param string $plugin Absolute path of a snapshot written by {@see self::snapshot()}.
     * @param string $providerVersion Provider fixture release.
     *
     * @return list<array<string, mixed>>
     */
    public static function repositories(string $plugin, string $providerVersion = '1.0.0'): array
    {
        $variant = $providerVersion === '1.0.0' ? 'provider-v1' : 'provider-v2';

        return [
            ['packagist.org' => false],
            self::pathRepository($plugin, 'php-forge/scaffold', self::PLUGIN_VERSION),
            self::pathRepository(
                self::root() . "/tests/Fixtures/composer/providers/{$variant}",
                'fixture/provider',
                $providerVersion,
            ),
            ...self::dependencies(),
        ];
    }

    /**
     * Returns the command prefix that runs Composer: `COMPOSER_BINARY`, then `composer.phar` or `composer` on `PATH`,
     * executed with the current PHP binary, skipping the current test when none is found.
     *
     * Call it from the test body, directly or through a helper: a skip thrown from a Testo hook aborts the test
     * instead.
     *
     * @throws SkipTest when no Composer binary is found.
     *
     * @return list<string>
     */
    public static function requireCommand(): array
    {
        return self::locate() ?? throw new SkipTest(
            'Composer is not available; set COMPOSER_BINARY or add composer to PATH.',
        );
    }

    /**
     * Returns the requirements of a consumer project that installs the plugin snapshot: the plugin at
     * {@see self::PLUGIN_VERSION}, plus every plugin requirement whose constraint carries a stability flag, such as
     * `@dev`, because Composer honors stability flags only in the root package.
     *
     * @return array<string, string>
     */
    public static function requirements(): array
    {
        $requirements = ['php-forge/scaffold' => self::PLUGIN_VERSION];

        foreach (self::pluginRequirements() as $name => $constraint) {
            if (preg_match('/@(?:stable|rc|beta|alpha|dev)\b/i', $constraint) === 1) {
                $requirements[$name] = $constraint;
            }
        }

        return $requirements;
    }

    /**
     * Runs `$arguments` with Composer in `$cwd`, offline, without interaction, and with `$home` as `COMPOSER_HOME`.
     *
     * @param list<string> $arguments Composer arguments, such as `['install']`.
     * @param string $cwd Working directory.
     * @param string $home Absolute path of the `COMPOSER_HOME` directory, created by Composer when missing.
     */
    public static function run(array $arguments, string $cwd, string $home): ProcessResult
    {
        return self::process([...self::requireCommand(), ...$arguments], $cwd, $home);
    }

    /**
     * Runs a PHP script, such as `vendor/bin/scaffold`, with the current PHP binary in `$cwd`.
     *
     * @param list<string> $arguments Script path followed by its arguments.
     * @param string $cwd Working directory.
     */
    public static function runPhp(array $arguments, string $cwd): ProcessResult
    {
        return self::process([PHP_BINARY, ...$arguments], $cwd);
    }

    /**
     * Copies the entries of this working tree that make up the plugin package to `$target`.
     *
     * @param string $target Absolute path of the snapshot directory.
     */
    public static function snapshot(string $target): void
    {
        self::ensureDirectory($target);

        foreach (self::PLUGIN_ENTRIES as $entry) {
            $source = self::root() . "/{$entry}";

            if (is_file($source)) {
                self::copyFile($source, "{$target}/{$entry}");

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $destination = "{$target}/{$entry}/" . substr($file->getPathname(), strlen($source) + 1);

                $file->isDir()
                    ? self::ensureDirectory($destination)
                    : self::copyFile($file->getPathname(), $destination);
            }
        }
    }

    /**
     * Copies `$source` to `$destination`, creating the parent directory.
     */
    private static function copyFile(string $source, string $destination): void
    {
        self::ensureDirectory(dirname($destination));

        if (!copy($source, $destination)) {
            throw new RuntimeException(sprintf('Could not copy "%s".', $source));
        }
    }

    /**
     * Returns the path repositories of the packages that the plugin requires, directly or transitively, read from this
     * `vendor/`; a package that this `vendor/` holds as a symlink, such as one installed from a path repository, is
     * linked instead of copied.
     *
     * @return list<array<string, mixed>>
     */
    private static function dependencies(): array
    {
        $installed = json_decode(
            (string) file_get_contents(self::root() . '/vendor/composer/installed.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $packages = is_array($installed) && is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        $byName = [];

        foreach ($packages as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $byName[$package['name']] = $package;
            }
        }

        $repositories = [];
        $queue = array_keys(self::pluginRequirements());

        while ($queue !== []) {
            $name = array_shift($queue);
            $package = $byName[$name] ?? null;

            if ($package === null) {
                continue;
            }

            unset($byName[$name]);

            $path = self::root() . "/vendor/{$name}";

            $repositories[] = self::pathRepository($path, $name, $package['version'], is_link($path));

            foreach (is_array($package['require'] ?? null) ? array_keys($package['require']) : [] as $required) {
                if (is_string($required) && str_contains($required, '/')) {
                    $queue[] = $required;
                }
            }
        }

        return $repositories;
    }

    /**
     * Creates `$path` when it does not exist.
     */
    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o777, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $path));
        }
    }

    /**
     * Returns the environment of a subprocess: the current one, offline, without interaction, Xdebug or audit, with
     * `$home` as `COMPOSER_HOME` when given, and without the variables that redirect Composer.
     *
     * @return array<string, string>
     */
    private static function environment(string|null $home): array
    {
        $environment = getenv();

        foreach (['COMPOSER', 'COMPOSER_VENDOR_DIR', 'COMPOSER_BIN_DIR', 'COMPOSER_AUTH', 'XDEBUG_CONFIG'] as $name) {
            unset($environment[$name]);
        }

        if ($home !== null) {
            $environment['COMPOSER_HOME'] = $home;
            $environment['COMPOSER_CACHE_DIR'] = "{$home}/cache";
        }

        return [
            ...$environment,
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_NO_AUDIT' => '1',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'XDEBUG_MODE' => 'off',
        ];
    }

    /**
     * Returns the command prefix for the first Composer found, or `null`.
     *
     * @return list<string>|null
     */
    private static function locate(): array|null
    {
        $configured = getenv('COMPOSER_BINARY');

        if (is_string($configured) && $configured !== '') {
            return is_file($configured) ? self::prefix($configured) : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            foreach (['composer.phar', 'composer'] as $name) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;

                if ($directory !== '' && is_file($candidate)) {
                    return self::prefix($candidate);
                }
            }
        }

        return null;
    }

    /**
     * Returns a path repository that installs `$url` as `$name` at `$version`.
     *
     * @param bool $symlink Whether to link `$url` instead of copying it.
     *
     * @return array<string, mixed>
     */
    private static function pathRepository(string $url, string $name, string $version, bool $symlink = false): array
    {
        return [
            'type' => 'path',
            'url' => $url,
            'options' => ['symlink' => $symlink, 'versions' => [$name => $version]],
        ];
    }

    /**
     * Returns the package requirements of the plugin, platform requirements such as `php` excluded, read from the
     * `require` section of this `composer.json`.
     *
     * @return array<string, string>
     */
    private static function pluginRequirements(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(self::root() . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $require = is_array($manifest) && is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
        $requirements = [];

        foreach ($require as $name => $constraint) {
            if (is_string($name) && is_string($constraint) && str_contains($name, '/')) {
                $requirements[$name] = $constraint;
            }
        }

        return $requirements;
    }

    /**
     * Returns the command prefix that executes a Composer file: the file itself on Windows when it is a batch file
     * without a PHP sibling, otherwise the current PHP binary followed by the file.
     *
     * @return list<string>
     */
    private static function prefix(string $file): array
    {
        if (str_ends_with($file, '.bat') || str_ends_with($file, '.cmd')) {
            $sibling = substr($file, 0, -4);

            foreach ([$sibling . '.phar', $sibling] as $candidate) {
                if (is_file($candidate)) {
                    return [PHP_BINARY, $candidate];
                }
            }

            return [$file];
        }

        return [PHP_BINARY, $file];
    }

    /**
     * Runs `$command` in `$cwd` and captures its output through temporary files, which cannot dead-lock.
     *
     * @param list<string> $command Executable followed by its arguments.
     * @param string $cwd Working directory.
     * @param string|null $home `COMPOSER_HOME` of the process, or `null` to keep the inherited one.
     */
    private static function process(array $command, string $cwd, string|null $home = null): ProcessResult
    {
        $base = sys_get_temp_dir() . '/scaffold-process-' . uniqid('', true);

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', "{$base}.out", 'w'], 2 => ['file', "{$base}.err", 'w']],
            $pipes,
            $cwd,
            self::environment($home),
        );

        if ($process === false) {
            throw new RuntimeException(sprintf('Could not start "%s".', implode(' ', $command)));
        }

        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }

        $result = new ProcessResult(
            proc_close($process),
            (string) file_get_contents("{$base}.out"),
            (string) file_get_contents("{$base}.err"),
        );

        unlink("{$base}.out");
        unlink("{$base}.err");

        return $result;
    }

    /**
     * Returns the absolute path of this working tree.
     */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
