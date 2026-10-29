<?php

declare(strict_types=1);

/**
 * Runs the scaffold console commands against a project while failing with exit code `97` as soon as a Composer IO,
 * package, script, plugin, event dispatcher or `Composer\Composer` class is autoloaded.
 *
 * Usage: `php guarded-cli.php <autoload.php> <project-root>`; prints the comma-separated exit codes.
 */

use PHPForge\Scaffold\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

$autoload = $argv[1] ?? '';
$projectRoot = $argv[2] ?? '';

require $autoload;

spl_autoload_register(
    static function (string $class): void {
        if (preg_match('/^Composer\\\\(?:(?:IO|Package|Script|Plugin|EventDispatcher)\\\\|Composer$)/', $class) === 1) {
            fwrite(STDERR, "Composer class loaded: {$class}\n");

            exit(97);
        }
    },
    true,
    true,
);

$application = new Application();

$application->setAutoExit(false);

$codes = [];

foreach ([['sync'], ['status'], ['diff', '--all'], ['prune'], ['sync', '--dry-run']] as $command) {
    $codes[] = $application->run(new ArgvInput(['scaffold', ...$command, '-d', $projectRoot]), new NullOutput());
}

echo implode(',', $codes);
