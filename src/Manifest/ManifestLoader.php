<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Manifest;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Filesystem;
use PHPForge\Scaffold\Security\PathValidator;
use RuntimeException;

use function is_array;
use function is_file;
use function is_string;
use function json_decode;

/**
 * Loads a provider manifest, inline in `extra.scaffold` or in the provider file named by `extra.scaffold.manifest`, and
 * expands it into file mappings.
 */
final readonly class ManifestLoader
{
    /**
     * @param ManifestSchema $schema Validates the manifest.
     * @param ManifestExpander $expander Expands the validated manifest into file mappings.
     */
    public function __construct(private ManifestSchema $schema, private ManifestExpander $expander) {}

    /**
     * Loads the provider manifest from the `extra` section of a provider package and expands it into file mappings,
     * without Composer's package classes.
     *
     * @param array<mixed> $extra `extra` section of the provider package.
     * @param string $packageName Composer package name of the provider.
     * @param string $packagePath Absolute path to the provider root.
     *
     * @throws RuntimeException when the manifest file is missing or unreadable, the manifest is invalid, or a `copy[]`
     * path does not exist.
     * @throws \JsonException when the external manifest file is not valid JSON.
     *
     * @return list<FileMapping> File mappings; empty when `$extra` declares no `scaffold` object.
     */
    public function loadFromExtra(array $extra, string $packageName, string $packagePath): array
    {
        $scaffold = $extra['scaffold'] ?? null;

        if (!is_array($scaffold)) {
            return [];
        }

        // The inline block may carry consumer-side keys ('manifest', 'allowed-packages', 'auto'); an external manifest
        // body may not.
        $validated = isset($scaffold['manifest'])
            ? $this->schema->validate($this->readExternal($scaffold['manifest'], $packageName, $packagePath))
            : $this->schema->validate($scaffold, true);

        return $this->expander->expand($validated, $packagePath, $packageName);
    }

    /**
     * Loads the provider manifest from the `composer.json` at the provider root and expands it into file mappings,
     * exactly like {@see self::loadFromExtra()}.
     *
     * @param string $packageName Composer package name of the provider.
     * @param string $packagePath Absolute path to the provider root.
     *
     * @throws RuntimeException when the provider `composer.json` or the manifest file is missing, unreadable or
     * invalid, or a `copy[]` path does not exist.
     * @throws \JsonException when the provider `composer.json` or the external manifest file is not valid JSON.
     *
     * @return list<FileMapping> File mappings; empty when the package declares no `extra.scaffold` object.
     */
    public function loadFromPath(string $packageName, string $packagePath): array
    {
        $composerJson = $this->decodeFile(
            $packagePath . '/composer.json',
            $packageName,
            'composer.json',
        );

        $extra = $composerJson['extra'] ?? [];

        return $this->loadFromExtra(is_array($extra) ? $extra : [], $packageName, $packagePath);
    }

    /**
     * Reads and decodes a provider JSON file that must contain an object.
     *
     * @param string $absolutePath Absolute path to the file.
     * @param string $packageName Provider package name, used in error messages.
     * @param string $label File description used in error messages.
     *
     * @throws RuntimeException when the file is missing, unreadable, or not a JSON object.
     * @throws \JsonException when the file is not valid JSON.
     *
     * @return array<mixed> Decoded object.
     */
    private function decodeFile(string $absolutePath, string $packageName, string $label): array
    {
        if (!is_file($absolutePath)) {
            throw new RuntimeException(
                Message::MANIFEST_PROVIDER_FILE_NOT_FOUND->getMessage($packageName, $label, $absolutePath),
            );
        }

        $content = Filesystem::read(
            $absolutePath,
            Message::MANIFEST_PROVIDER_FILE_UNREADABLE->getMessage($packageName, $label, $absolutePath),
        );

        $raw = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($raw)) {
            throw new RuntimeException(
                Message::MANIFEST_PROVIDER_FILE_NOT_OBJECT->getMessage($packageName, $label, $absolutePath),
            );
        }

        return $raw;
    }

    /**
     * Reads and decodes the external manifest file named by `extra.scaffold.manifest`.
     *
     * @param mixed $manifestPath Raw `manifest` value, expected to be a non-empty relative path inside the provider.
     * @param string $packageName Provider package name, used in error messages.
     * @param string $packagePath Absolute path to the provider root.
     *
     * @throws RuntimeException when the path is invalid or the file is missing, unreadable, or not a JSON object.
     * @throws \JsonException when the file is not valid JSON.
     *
     * @return array<mixed> Decoded manifest.
     */
    private function readExternal(mixed $manifestPath, string $packageName, string $packagePath): array
    {
        if (!is_string($manifestPath) || $manifestPath === '') {
            throw new RuntimeException(
                Message::MANIFEST_REFERENCE_EMPTY->getMessage($packageName),
            );
        }

        if (PathValidator::isAbsolute($manifestPath) || PathValidator::hasTraversal($manifestPath)) {
            throw new RuntimeException(
                Message::MANIFEST_REFERENCE_NOT_RELATIVE->getMessage($packageName),
            );
        }

        return $this->decodeFile($packagePath . '/' . $manifestPath, $packageName, 'manifest file');
    }
}
