<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console;

use Composer\InstalledVersions;
use OutOfBoundsException;
use Override;
use PHPForge\Scaffold\Console\Command\{
    DiffCommand,
    EjectCommand,
    ProvidersCommand,
    PruneCommand,
    ReapplyCommand,
    StatusCommand,
    SyncCommand,
};
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\{InputDefinition, InputOption};

/**
 * Runs the `status`, `providers`, `diff`, `eject`, `reapply`, `prune` and `sync` commands as the
 * `vendor/bin/scaffold` console application.
 */
final class Application extends SymfonyApplication
{
    /**
     * Version reported when Composer's runtime metadata does not list the package.
     */
    public const string FALLBACK_VERSION = '0.3.x-dev';
    /**
     * Application / Composer package name.
     */
    public const string NAME = 'php-forge/scaffold';

    /**
     * Creates the application and registers the scaffold commands.
     */
    public function __construct()
    {
        parent::__construct(self::NAME, self::resolveVersion());

        $this->addCommands(
            [
                new StatusCommand(),
                new ProvidersCommand(),
                new DiffCommand(),
                new EjectCommand(),
                new ReapplyCommand(),
                new PruneCommand(),
                new SyncCommand(),
            ],
        );
    }

    /**
     * Returns the installed version of `$package` from Composer's runtime metadata, or {@see self::FALLBACK_VERSION}
     * when the metadata does not list it.
     *
     * @param string $package Composer package name to query; defaults to this application's package.
     *
     * @return string Installed pretty version (for example, `1.2.0` or `dev-main`), or the fallback version.
     */
    public static function resolveVersion(string $package = self::NAME): string
    {
        try {
            return InstalledVersions::getPrettyVersion($package) ?? self::FALLBACK_VERSION;
        } catch (OutOfBoundsException) {
            return self::FALLBACK_VERSION;
        }
    }

    /**
     * Returns the default input definition with the global `--working-dir` (`-d`) option added.
     *
     * @return InputDefinition Symfony's default definition extended with `--working-dir`.
     */
    #[Override]
    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        $definition->addOption(
            new InputOption(
                'working-dir',
                'd',
                InputOption::VALUE_REQUIRED,
                'Use the given directory as the starting point of the project root search.',
            ),
        );

        return $definition;
    }
}
