<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Manifest;

use PHPForge\Scaffold\Manifest\ManifestSchema;
use PHPForge\Scaffold\Tests\Provider\ManifestSchemaProvider;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * Unit tests for {@see ManifestSchema} validation of the `copy` / `exclude` / `modes` / `merge` shape and its keys.
 *
 * {@see ManifestSchemaProvider} for test case data providers.
 */
#[Test]
#[Covers(ManifestSchema::class)]
final class ManifestSchemaTest
{
    /**
     * Ensures an invalid manifest is rejected with the message naming the offending key or entry.
     *
     * @param array<mixed> $raw Raw manifest.
     */
    #[DataProvider(ManifestSchemaProvider::class . '::invalidManifests')]
    public function throwRuntimeExceptionForInvalidManifest(array $raw, bool $inline, string $message): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessage($message);

        (new ManifestSchema())->validate($raw, $inline);
    }

    /**
     * Ensures a valid manifest is normalised into the typed structure with defaults for the optional keys.
     *
     * @param array<mixed> $raw Raw manifest.
     * @param array<string, mixed> $expected Validated manifest.
     */
    #[DataProvider(ManifestSchemaProvider::class . '::validManifests')]
    public function validateReturnsTheTypedStructure(array $raw, bool $inline, array $expected): void
    {
        Assert::same(
            (new ManifestSchema())->validate($raw, $inline),
            $expected,
            'Validated manifest must match.',
        );
    }
}
