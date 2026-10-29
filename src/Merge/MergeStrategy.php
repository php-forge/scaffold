<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Merge;

/**
 * Merges provider content into consumer content without removing, reordering or overriding consumer content.
 */
interface MergeStrategy
{
    /**
     * Adds the provider entries missing from the consumer.
     *
     * @param string $provider Provider content whose missing entries are added.
     * @param string $consumer Consumer content; an empty string stands for an absent file.
     *
     * @return MergeResult Merged content and whether it differs from the consumer.
     */
    public function merge(string $provider, string $consumer): MergeResult;
}
