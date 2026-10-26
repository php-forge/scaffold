<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Security;

use PHPForge\Scaffold\Security\PackageAllowlist;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for {@see PackageAllowlist} authorization enforcement.
 */
#[Group('scaffold')]
#[Group('security')]
final class PackageAllowlistTest extends TestCase
{
    public function testAssertAllowedPassesSilentlyForAuthorizedPackage(): void
    {
        $this->expectNotToPerformAssertions();

        $list = new PackageAllowlist(['php-forge/nginx-scaffold']);

        $list->assertAllowed('php-forge/nginx-scaffold');
    }

    public function testAssertAllowedThrowsForEmptyAllowlist(): void
    {
        $list = new PackageAllowlist([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('allowed-packages');

        $list->assertAllowed('any/package');
    }

    public function testAssertAllowedThrowsForUnauthorizedPackage(): void
    {
        $list = new PackageAllowlist(['php-forge/app-base-scaffold']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php-forge/nginx-scaffold');

        $list->assertAllowed('php-forge/nginx-scaffold');
    }

    public function testEmptyAllowlistRejectsEveryPackage(): void
    {
        $list = new PackageAllowlist([]);

        self::assertFalse(
            $list->isAllowed('php-forge/anything'),
            'Empty allowlist should reject all packages.',
        );
    }

    public function testIsAllowedUsesStrictStringComparison(): void
    {
        $list = new PackageAllowlist(['php-forge/nginx-scaffold']);

        self::assertFalse(
            $list->isAllowed('php-forge/apache-scaffold'),
            'Package not in allowlist should not be allowed.',
        );
        self::assertTrue(
            $list->isAllowed('php-forge/nginx-scaffold'),
            'Package in allowlist should be allowed.',
        );
    }

    public function testMultiplePackagesInAllowlist(): void
    {
        $list = new PackageAllowlist([
            'php-forge/app-base-scaffold',
            'php-forge/nginx-scaffold',
            'php-forge/inertia-vue-scaffold',
        ]);

        self::assertTrue(
            $list->isAllowed('php-forge/app-base-scaffold'),
            'First package in allowlist should be allowed.',
        );
        self::assertTrue(
            $list->isAllowed('php-forge/nginx-scaffold'),
            'Second package in allowlist should be allowed.',
        );
        self::assertTrue(
            $list->isAllowed('php-forge/inertia-vue-scaffold'),
            'Third package in allowlist should be allowed.',
        );
        self::assertFalse(
            $list->isAllowed('php-forge/apache-scaffold'),
            'Package not in allowlist should not be allowed.',
        );
    }

    public function testPackageInAllowlistIsAllowed(): void
    {
        $list = new PackageAllowlist(['php-forge/app-base-scaffold', 'php-forge/nginx-scaffold']);

        self::assertTrue(
            $list->isAllowed('php-forge/app-base-scaffold'),
            'Package in allowlist should be allowed.',
        );
    }

    public function testPackageNotInAllowlistIsNotAllowed(): void
    {
        $list = new PackageAllowlist(['php-forge/app-base-scaffold']);

        self::assertFalse(
            $list->isAllowed('php-forge/nginx-scaffold'),
            'Package not in allowlist should not be allowed.',
        );
    }
}
