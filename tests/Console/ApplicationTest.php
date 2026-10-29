<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Console;

use Composer\InstalledVersions;
use PHPForge\Scaffold\Console\Application;
use PHPForge\Scaffold\Console\Command\{
    DiffCommand,
    EjectCommand,
    ProvidersCommand,
    PruneCommand,
    ReapplyCommand,
    StatusCommand,
    SyncCommand,
};
use Symfony\Component\Console\Command\{Command, CompleteCommand, DumpCompletionCommand, HelpCommand, ListCommand};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function array_map;
use function ksort;

/**
 * Unit tests for {@see Application} verifying command registration, name stability, and the runtime-derived
 * version lookup via Composer metadata.
 */
#[Test]
#[Covers(Application::class)]
final class ApplicationTest
{
    /**
     * Ensures the application name is the fixed package name.
     */
    public function nameIsStable(): void
    {
        Assert::same(
            (new Application())->getName(),
            Application::NAME,
            "Name must be 'Application::NAME'.",
        );
    }

    /**
     * Pins the full command list of the application, the seven scaffold commands plus the Symfony built-ins.
     */
    public function registersEveryCommand(): void
    {
        $commands = array_map(
            static fn(Command $command): string => $command::class,
            (new Application())->all(),
        );

        ksort($commands);

        Assert::same(
            $commands,
            [
                '_complete' => CompleteCommand::class,
                'completion' => DumpCompletionCommand::class,
                'diff' => DiffCommand::class,
                'eject' => EjectCommand::class,
                'help' => HelpCommand::class,
                'list' => ListCommand::class,
                'providers' => ProvidersCommand::class,
                'prune' => PruneCommand::class,
                'reapply' => ReapplyCommand::class,
                'status' => StatusCommand::class,
                'sync' => SyncCommand::class,
            ],
            'Every command must be registered under its name.',
        );
    }

    /**
     * Ensures an unknown Composer package falls back to the fixed version.
     */
    public function resolveVersionFallsBackWhenPackageIsNotInstalled(): void
    {
        Assert::same(
            Application::resolveVersion('definitely/not-a-real-package-for-tests'),
            Application::FALLBACK_VERSION,
            "Unknown package must yield 'Application::FALLBACK_VERSION'.",
        );
    }

    /**
     * Ensures the application version is the Composer pretty version of the installed package.
     */
    public function versionIsTheInstalledPrettyVersion(): void
    {
        $prettyVersion = InstalledVersions::getPrettyVersion(Application::NAME);

        Assert::notNull(
            $prettyVersion,
            "Precondition: Composer must report a 'pretty_version' for 'Application::NAME'.",
        );
        Assert::same(
            (new Application())->getVersion(),
            $prettyVersion,
            "Version must be the Composer 'pretty_version'.",
        );
    }
}
