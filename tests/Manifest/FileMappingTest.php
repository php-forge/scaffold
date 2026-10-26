<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Manifest;

use Error;
use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for {@see FileMapping} value object construction and property access.
 */
#[Group('scaffold')]
#[Group('manifest')]
final class FileMappingTest extends TestCase
{
    public function testConstructorAssignsDestination(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Preserve,
            'vendor/pkg',
            '/path',
        );

        self::assertSame(
            'config/params.php',
            $mapping->destination,
            'Constructor should assign the destination property correctly.',
        );
    }

    public function testConstructorAssignsMode(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Replace,
            'vendor/pkg',
            '/path',
        );

        self::assertSame(
            FileMode::Replace,
            $mapping->mode,
            'Constructor should assign the mode property correctly.',
        );
    }

    public function testConstructorAssignsProviderName(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Preserve,
            'php-forge/nginx-scaffold',
            '/path',
        );

        self::assertSame(
            'php-forge/nginx-scaffold',
            $mapping->providerName,
            'Constructor should assign the providerName property correctly.',
        );
    }

    public function testConstructorAssignsProviderPath(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Preserve,
            'vendor/pkg',
            '/vendor/php-forge/nginx-scaffold',
        );

        self::assertSame(
            '/vendor/php-forge/nginx-scaffold',
            $mapping->providerPath,
            'Constructor should assign the providerPath property correctly.',
        );
    }

    public function testConstructorAssignsSource(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Preserve,
            'vendor/pkg',
            '/path',
        );

        self::assertSame(
            'stubs/params.php',
            $mapping->source,
            'Constructor should assign the source property correctly.',
        );
    }

    public function testPropertiesAreReadonly(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Preserve,
            'vendor/pkg',
            '/path',
        );

        $this->expectException(Error::class);

        (new ReflectionProperty(FileMapping::class, 'destination'))->setValue($mapping, 'other.php');
    }
}
