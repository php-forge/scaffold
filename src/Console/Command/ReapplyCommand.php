<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console\Command;

use PHPForge\Scaffold\Console\{SymfonyOutputWriter, VendorDirResolver};
use PHPForge\Scaffold\Services\ReapplyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-applies scaffold stubs to the project, optionally overwriting user-modified files.
 *
 * Usage example:
 * ```bash
 * vendor/bin/scaffold reapply
 * vendor/bin/scaffold reapply config/params.php
 * vendor/bin/scaffold reapply config/params.php --force
 * vendor/bin/scaffold reapply --provider=php-forge/baseline
 * ```
 */
#[AsCommand(
    name: 'reapply',
    description: 'Re-applies scaffold stubs to the project, optionally overwriting user-modified files.',
)]
final class ReapplyCommand extends AbstractScaffoldCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'file',
                InputArgument::OPTIONAL,
                'Destination path to reapply. When empty, all tracked files are processed.',
                '',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite user-modified files without prompting.',
            )
            ->addOption(
                'provider',
                null,
                InputOption::VALUE_REQUIRED,
                "Restrict reapply to a provider package name (for example, 'php-forge/baseline').",
                '',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->resolveProjectRoot($output);

        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        /** @var string $file */
        $file = $input->getArgument('file');

        /** @var string $provider */
        $provider = $input->getOption('provider');

        return (new ReapplyService())->run(
            $projectRoot,
            VendorDirResolver::resolve($projectRoot),
            $file,
            $provider,
            (bool) $input->getOption('force'),
            new SymfonyOutputWriter($output),
        );
    }
}
