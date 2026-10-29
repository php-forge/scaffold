<?php

declare(strict_types=1);

namespace PHPForge\Scaffold;

use function sprintf;

/**
 * Formats the `sprintf` template held by the backing value of a message enum case.
 */
trait MessageTemplate
{
    /**
     * Returns the message template formatted with `$argument`.
     *
     * @param int|string ...$argument Values substituted into the template.
     *
     * @return string Formatted message.
     */
    public function getMessage(int|string ...$argument): string
    {
        return sprintf($this->value, ...$argument);
    }

    /**
     * Returns the text of `$one` when `$count` is `1`, and the text of `$many` otherwise.
     *
     * @param int $count Number of items the fragment counts.
     * @param self $one Fragment for a single item.
     * @param self $many Fragment for zero or several items.
     *
     * @return string Text of the fragment that agrees with `$count`.
     */
    public static function plural(int $count, self $one, self $many): string
    {
        return ($count === 1 ? $one : $many)->getMessage();
    }
}
