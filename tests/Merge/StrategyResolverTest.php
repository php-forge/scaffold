<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Merge;

use PHPForge\Scaffold\Merge\{MergeStrategy, MergeStrategyKind, StrategyResolver};
use PHPForge\Scaffold\Tests\Provider\StrategyResolverProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see StrategyResolver} merge strategy selection.
 *
 * {@see StrategyResolverProvider} for test case data providers.
 */
#[Test]
#[Covers(StrategyResolver::class)]
final class StrategyResolverTest
{
    /**
     * Ensures every kind maps to the strategy that implements it.
     *
     * @param class-string<MergeStrategy> $class Expected strategy class.
     */
    #[DataProvider(StrategyResolverProvider::class . '::kinds')]
    public function forKindReturnsMatchingStrategy(MergeStrategyKind $kind, string $class): void
    {
        Assert::same(
            StrategyResolver::forKind($kind)::class,
            $class,
            "Kind '{$kind->value}' must map to its strategy.",
        );
    }
    /**
     * Ensures the default strategy follows the destination basename.
     *
     * @param class-string<MergeStrategy> $class Expected strategy class.
     */
    #[DataProvider(StrategyResolverProvider::class . '::destinations')]
    public function forReturnsDefaultStrategyByBasename(string $destination, string $class): void
    {
        Assert::same(
            StrategyResolver::for($destination)::class,
            $class,
            "Default strategy of '{$destination}' must match.",
        );
    }
}
