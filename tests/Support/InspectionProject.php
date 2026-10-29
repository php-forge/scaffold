<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Scaffold\Lock\{Hasher, LockFile};
use RuntimeException;

use function array_filter;
use function dirname;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function sprintf;
use function unlink;
use function var_export;

/**
 * Builds a consumer project with providers under `<root>/vendor` and a hand-written `scaffold-lock.json`, for the
 * inspection services and commands.
 */
final readonly class InspectionProject
{
    /**
     * @param string $root Absolute path to the project root; it must exist.
     */
    public function __construct(public string $root) {}

    /**
     * Writes the root `composer.json` with the given `allowed-packages`.
     *
     * @param list<string> $allowed Package names allowed to write scaffold files.
     */
    public function composer(array $allowed): self
    {
        $this->write(
            'composer.json',
            json_encode(['extra' => ['scaffold' => ['allowed-packages' => $allowed]]], JSON_THROW_ON_ERROR),
        );

        return $this;
    }

    /**
     * Returns the context of the project, whose `composer.json` keeps the default vendor directory `<root>/vendor`.
     */
    public function context(): ProjectContext
    {
        return ProjectContext::create($this->root);
    }

    /**
     * Removes a project file.
     */
    public function delete(string $destination): self
    {
        unlink("{$this->root}/{$destination}");

        return $this;
    }

    /**
     * Writes a project file.
     */
    public function file(string $destination, string $content): self
    {
        $this->write($destination, $content);

        return $this;
    }

    /**
     * Writes `vendor/composer/installed.php` listing the given packages with their versions.
     *
     * @param array<string, string> $versions Pretty versions keyed by package name.
     */
    public function installed(array $versions): self
    {
        $packages = [];

        foreach ($versions as $name => $version) {
            $packages[$name] = ['pretty_version' => $version, 'install_path' => "{$this->root}/vendor/{$name}"];
        }

        $this->write(
            'vendor/composer/installed.php',
            '<?php return ' . var_export(['root' => [], 'versions' => $packages], true) . ';',
        );

        return $this;
    }

    /**
     * Returns the lock file of the project.
     */
    public function lock(): LockFile
    {
        return new LockFile($this->root);
    }

    /**
     * Writes a provider package with one stub per destination at `stubs/<destination>`.
     *
     * @param string $name Provider package name.
     * @param array<string, string> $files Stub contents keyed by destination.
     * @param array<string, string> $modes Manifest `modes` map.
     * @param array<string, string> $merge Manifest `merge` map.
     */
    public function provider(string $name, array $files, array $modes = [], array $merge = []): self
    {
        $copy = [];

        foreach ($files as $destination => $content) {
            $copy[] = ['from' => "stubs/{$destination}", 'to' => $destination];

            $this->stub($name, $destination, $content);
        }

        $scaffold = ['copy' => $copy];

        if ($modes !== []) {
            $scaffold['modes'] = $modes;
        }

        if ($merge !== []) {
            $scaffold['merge'] = $merge;
        }

        $this->write(
            "vendor/{$name}/composer.json",
            json_encode(['name' => $name, 'extra' => ['scaffold' => $scaffold]], JSON_THROW_ON_ERROR),
        );

        return $this;
    }

    /**
     * Overwrites the stub of a destination in a provider package.
     */
    public function stub(string $provider, string $destination, string $content): self
    {
        $this->write("vendor/{$provider}/stubs/{$destination}", $content);

        return $this;
    }

    /**
     * Records a lock entry with the current disk hash and the current stub hash, merged with `$extra`.
     *
     * @param array<string, bool|string|null> $extra Entry keys that replace or extend the computed ones; `null` removes
     * the key.
     */
    public function track(
        string $destination,
        string $provider,
        string $mode = 'replace',
        array $extra = [],
        string $version = '1.0.0',
    ): self {
        $lock = $this->lock();

        $data = $lock->read();

        $diskPath = "{$this->root}/{$destination}";
        $stubPath = "{$this->root}/vendor/{$provider}/stubs/{$destination}";

        $entry = [
            'hash' => is_file($diskPath) ? Hasher::hash($diskPath) : 'sha256:none',
            'provider' => $provider,
            'source' => "stubs/{$destination}",
            'mode' => $mode,
        ];

        if (is_file($stubPath)) {
            $entry['stub-hash'] = Hasher::hash($stubPath);
        }

        $data['providers'][$provider] = ['version' => $version, 'path' => "vendor/{$provider}"];
        $data['files'][$destination] = array_filter(
            [...$entry, ...$extra],
            static fn(bool|string|null $value): bool => $value !== null,
        );

        $lock->write($data);

        return $this;
    }

    /**
     * Writes `$content` to a path relative to the project root, creating parent directories.
     */
    private function write(string $relative, string $content): void
    {
        $path = "{$this->root}/{$relative}";
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }
}
