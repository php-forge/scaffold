<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Manifest;

use Error;
use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use ReflectionProperty;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function get_object_vars;

/**
 * Unit tests for {@see FileMapping} value object construction and immutability.
 */
#[Test]
#[Covers(FileMapping::class)]
final class FileMappingTest
{
    /**
     * Ensures the constructor assigns every property and defaults the merge strategy to `null`.
     */
    public function constructorAssignsEveryProperty(): void
    {
        Assert::same(
            get_object_vars(
                new FileMapping(
                    'config/params.php',
                    'stubs/params.php',
                    FileMode::Preserve,
                    'php-forge/nginx-scaffold',
                    '/vendor/php-forge/nginx-scaffold',
                ),
            ),
            [
                'destination' => 'config/params.php',
                'source' => 'stubs/params.php',
                'mode' => FileMode::Preserve,
                'providerName' => 'php-forge/nginx-scaffold',
                'providerPath' => '/vendor/php-forge/nginx-scaffold',
                'mergeStrategy' => null,
            ],
            "Properties must hold the constructor arguments and a 'null' merge strategy.",
        );
    }

    /**
     * Ensures a property cannot be modified after construction.
     */
    public function throwErrorWhenAPropertyIsModified(): void
    {
        $mapping = new FileMapping('config/params.php', 'stubs/params.php', FileMode::Preserve, 'vendor/pkg', '/path');

        Expect::exception(Error::class)
            ->withMessage('Cannot modify readonly property ' . FileMapping::class . '::$destination');

        (new ReflectionProperty(FileMapping::class, 'destination'))
            ->setValue($mapping, 'other.php');
    }
}
