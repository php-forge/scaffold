<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use RuntimeException;

use function dirname;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function sprintf;

/**
 * Builds a temporary fake project and vendor directory for functional scaffold tests.
 */
final readonly class FakeProjectBuilder
{
    /**
     * Absolute path of the fake project root.
     */
    private string $projectRoot;
    /**
     * Absolute path of the fake vendor directory, a sibling of the project root.
     */
    private string $vendorDir;

    /**
     * @param string $tempBase Absolute path of the temporary directory of the test.
     */
    public function __construct(string $tempBase)
    {
        $this->projectRoot = "{$tempBase}/project";
        $this->vendorDir = "{$tempBase}/vendor";

        self::ensureDirectory($this->projectRoot);
        self::ensureDirectory($this->vendorDir);
    }

    /**
     * Writes a `composer.json` at the project root from the given array.
     *
     * @param array<string, mixed> $config Root composer.json content to serialize.
     */
    public function createComposerJson(array $config): void
    {
        self::write(
            "{$this->projectRoot}/composer.json",
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Creates a file in the project root (simulates a pre-existing project file).
     *
     * @param string $relPath Path relative to the project root.
     * @param string $content File content to write.
     */
    public function createProjectFile(string $relPath, string $content): void
    {
        self::write("{$this->projectRoot}/{$relPath}", $content);
    }

    /**
     * Creates a stub file for a provider package inside the fake vendor directory.
     *
     * @param string $providerName Composer package name (for example, `php-forge/app-base-scaffold`).
     * @param string $relPath Path relative to the provider root (for example, `stubs/config/params.php`).
     * @param string $content File content to write.
     */
    public function createStubFile(string $providerName, string $relPath, string $content): void
    {
        self::write("{$this->vendorDir}/{$providerName}/{$relPath}", $content);
    }

    /**
     * Returns the absolute path to the fake project root.
     */
    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Returns the absolute path to the fake vendor directory.
     */
    public function getVendorDir(): string
    {
        return $this->vendorDir;
    }

    /**
     * Creates `$path` when it does not exist.
     *
     * @throws RuntimeException if the directory cannot be created.
     */
    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o777, recursive: true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $path));
        }
    }

    /**
     * Writes `$content` to `$path`, creating the parent directory.
     *
     * @throws RuntimeException if the file cannot be written.
     */
    private static function write(string $path, string $content): void
    {
        self::ensureDirectory(dirname($path));

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('Could not write file "%s".', $path));
        }
    }
}
