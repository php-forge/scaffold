<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console\Command;

use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\SymfonyOutputWriter;
use PHPForge\Scaffold\Services\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputInterface, InputOption};

/**
 * Applies the allowed and installed providers to the project outside Composer, as `composer install` would.
 */
#[AsCommand(
    name: 'sync',
    description: 'Applies the allowed providers to the project outside Composer, as composer install would.',
)]
final class SyncCommand extends AbstractScaffoldCommand
{
    /**
     * Declares the `--dry-run`, `--exit-code` and `--force` options.
     */
    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print what applying the providers would change, and the orphans, without writing anything.',
            )
            ->addOption(
                'exit-code',
                null,
                InputOption::VALUE_NONE,
                'With --dry-run, exit with 1 when applying would change a file or scaffold-lock.json.',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite user-modified replace files, like reapply --force.',
            );
    }

    /**
     * Synchronizes the project.
     *
     * @param InputInterface $input Input with the `--dry-run`, `--exit-code` and `--force` options.
     * @param SymfonyOutputWriter $out Output sink that receives the sync report and the error messages.
     * @param string $projectRoot Absolute path of the project root.
     *
     * @return int Exit code of {@see SyncService::run()}.
     */
    protected function handle(InputInterface $input, SymfonyOutputWriter $out, string $projectRoot): int
    {
        return (new SyncService())->run(
            ProjectContext::create($projectRoot),
            $out,
            (bool) $input->getOption('dry-run'),
            (bool) $input->getOption('exit-code'),
            (bool) $input->getOption('force'),
            $out->isDecorated(),
        );
    }
}
