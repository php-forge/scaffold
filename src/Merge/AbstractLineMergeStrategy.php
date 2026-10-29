<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Merge;

use function array_map;

/**
 * Provides the merge pipeline shared by the line-oriented strategies.
 *
 * @phpstan-type MergePlan array{insertAfter: array<int, list<string>>, blocks: list<list<string>>}
 */
abstract class AbstractLineMergeStrategy implements MergeStrategy
{
    /**
     * Computes the insertions that make the consumer satisfy the provider.
     *
     * @param list<string> $provider Provider lines.
     * @param list<string> $consumer Consumer lines without terminators.
     *
     * @return MergePlan Lines to insert after a consumer line index (`-1` inserts at the top) and blocks to append at
     * the end; both empty when the consumer already satisfies the provider.
     */
    abstract protected function plan(array $provider, array $consumer): array;

    /**
     * Adds the provider entries missing from the consumer.
     *
     * @param string $provider Provider content whose missing entries are added.
     * @param string $consumer Consumer content; an empty string stands for an absent file.
     *
     * @return MergeResult Merged content and whether it differs from the consumer.
     */
    public function merge(string $provider, string $consumer): MergeResult
    {
        $providerLines = Lines::split(Lines::normalise($provider));

        if ($consumer === '') {
            return new MergeResult($provider, $provider !== '');
        }

        $segments = Lines::splitRaw($consumer);

        $plan = $this->plan(
            $providerLines,
            array_map(static fn(array $segment): string => $segment[0], $segments),
        );

        if ($plan['insertAfter'] === [] && $plan['blocks'] === []) {
            return new MergeResult($consumer, false);
        }

        $content = Lines::rebuild(
            $segments,
            $plan['insertAfter'],
            $plan['blocks'],
            Lines::detectEol($consumer),
            Lines::endsWithEol($consumer) || Lines::endsWithEol($provider),
        );

        return new MergeResult($content, $content !== $consumer);
    }
}
