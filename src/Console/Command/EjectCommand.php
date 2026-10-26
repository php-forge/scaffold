<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console\Command;

use PHPForge\Scaffold\Console\SymfonyOutputWriter;
use PHPForge\Scaffold\Services\EjectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes a file entry from `scaffold-lock.json` without deleting the file from disk.
 *
 * Usage example:
 * ```bash
 * vendor/bin/scaffold eject config/params.php --yes
 * ```
 */
#[AsCommand(
    name: 'eject',
    description: "Removes a file entry from 'scaffold-lock.json' without deleting the file from disk.",
)]
final class EjectCommand extends AbstractScaffoldCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, "Destination path as recorded in 'scaffold-lock.json'.")
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Confirm the ejection without prompting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->resolveProjectRoot($output);

        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        /** @var string $file */
        $file = $input->getArgument('file');

        return (new EjectService())->run(
            $projectRoot,
            $file,
            (bool) $input->getOption('yes'),
            new SymfonyOutputWriter($output),
        );
    }
}
