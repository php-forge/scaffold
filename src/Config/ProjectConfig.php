<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Config;

use JsonException;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use RuntimeException;

use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;

/**
 * Represents the consumer settings read from the project `composer.json`: `extra.scaffold` and `config.vendor-dir`.
 */
final readonly class ProjectConfig
{
    /**
     * Creates consumer settings from scaffold configuration and the configured vendor-directory value.
     *
     * @param list<string> $allowedPackages Package names allowed to write scaffold files, in declaration order.
     * @param bool $auto Whether the Composer hooks scaffold automatically.
     * @param int $ignoredEntries Number of non-string `allowed-packages` entries that were ignored.
     * @param bool $declared Whether `extra.scaffold` is an object.
     * @param string|null $vendorDir Non-empty `config.vendor-dir` as written in `composer.json`, or `null` when unset.
     */
    public function __construct(
        public array $allowedPackages = [],
        public bool $auto = true,
        public int $ignoredEntries = 0,
        public bool $declared = false,
        public string|null $vendorDir = null,
    ) {}

    /**
     * Reads the configuration from a `composer.json` file.
     *
     * @param string $path Absolute path to the project `composer.json`.
     *
     * @throws RuntimeException when the file is missing, unreadable, or not a JSON object.
     *
     * @return self Configuration from `extra.scaffold` and `config.vendor-dir`, or the defaults when they are absent.
     */
    public static function fromComposerJson(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                Message::CONFIG_MANIFEST_NOT_FOUND->getMessage($path),
            );
        }

        $content = Filesystem::read($path, Message::CONFIG_MANIFEST_UNREADABLE->getMessage($path));

        try {
            $decoded = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                Message::CONFIG_MANIFEST_INVALID_JSON->getMessage($path, $e->getMessage()),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(
                Message::CONFIG_MANIFEST_NOT_OBJECT->getMessage($path),
            );
        }

        $extra = $decoded['extra'] ?? [];

        $vendorDir = is_array($decoded['config'] ?? null) ? $decoded['config']['vendor-dir'] ?? null : null;

        $config = self::fromExtra(is_array($extra) ? $extra : []);

        return new self(
            $config->allowedPackages,
            $config->auto,
            $config->ignoredEntries,
            $config->declared,
            is_string($vendorDir) && $vendorDir !== '' ? $vendorDir : null,
        );
    }

    /**
     * Reads the configuration from the `extra` section of the root package; only an explicit `false` in `auto`
     * disables the Composer hooks.
     *
     * @param array<mixed> $extra `extra` section of the root package.
     *
     * @return self Configuration from `extra.scaffold`, or the defaults when it is absent or not an object.
     */
    public static function fromExtra(array $extra): self
    {
        $scaffold = $extra['scaffold'] ?? null;

        if (!is_array($scaffold)) {
            return new self();
        }

        $rawAllowed = $scaffold['allowed-packages'] ?? null;

        $allowed = [];
        $ignored = 0;

        if (is_array($rawAllowed)) {
            foreach ($rawAllowed as $item) {
                if (is_string($item)) {
                    $allowed[] = $item;
                } else {
                    $ignored++;
                }
            }
        }

        return new self($allowed, ($scaffold['auto'] ?? null) !== false, $ignored, true);
    }

    /**
     * Returns whether `$packageName` is listed in `allowed-packages`.
     *
     * @param string $packageName Package name to check.
     *
     * @return bool `true` when the package is allowed to write scaffold files, `false` otherwise.
     */
    public function isAllowed(string $packageName): bool
    {
        return in_array($packageName, $this->allowedPackages, true);
    }
}
