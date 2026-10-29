<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console;

/**
 * Represents the exit codes that scaffold services return to the console commands.
 */
enum ExitCode: int
{
    /**
     * Reports a failure such as an untracked file, an unsafe lock entry, a missing stub, a read error, or a `reapply`
     * filter that matches no tracked file.
     */
    case Error = 1;

    /**
     * Reports success, including previews and runs that change nothing.
     */
    case Ok = 0;
}
