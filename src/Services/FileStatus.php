<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

/**
 * Represents the state of a scaffold file, tracked in `scaffold-lock.json` or pending, as reported by the `status`
 * command.
 */
enum FileStatus: string
{
    /**
     * The destination is a `replace` file changed by the consumer while the provider stub also changed.
     */
    case Conflict = 'conflict';

    /**
     * The destination was ejected and scaffold no longer manages it.
     */
    case Ejected = 'ejected';

    /**
     * The lock entry is unsafe, or the destination, stub or provider manifest cannot be read.
     */
    case Error = 'error';

    /**
     * The destination is absent from disk.
     */
    case Missing = 'missing';

    /**
     * The `replace` or `preserve` destination changed since the last apply.
     */
    case Modified = 'modified';

    /**
     * No allowed and installed provider ships the destination anymore.
     */
    case Orphan = 'orphan';

    /**
     * The provider stub changed since the last apply, or an `append`/`prepend` destination lacks provider content.
     */
    case Outdated = 'outdated';
    /**
     * An allowed and installed provider ships the destination, but `scaffold-lock.json` has no entry for it yet.
     */
    case Pending = 'pending';

    /**
     * The destination matches the last apply and the current provider stub.
     */
    case Synced = 'synced';

    /**
     * Returns the states in the order the summary line lists them.
     *
     * @return list<self> Every state in summary order, from `synced` to `error`.
     */
    public static function summaryOrder(): array
    {
        return [
            self::Synced,
            self::Pending,
            self::Outdated,
            self::Modified,
            self::Conflict,
            self::Missing,
            self::Orphan,
            self::Ejected,
            self::Error,
        ];
    }
}
