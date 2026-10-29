<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console\Command;

use PHPForge\Scaffold\Console\SymfonyOutputWriter;
use PHPForge\Scaffold\Services\ProvidersService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Lists all scaffold providers recorded in `scaffold-lock.json` with their file counts.
 */
#[AsCommand(
    name: 'providers',
    description: "Lists all scaffold providers recorded in 'scaffold-lock.json' with their file counts.",
)]
final class ProvidersCommand extends AbstractScaffoldCommand
{
    /**
     * Prints the provider table.
     *
     * @param InputInterface $input Input that may carry the global `--working-dir` option.
     * @param SymfonyOutputWriter $out Output sink that receives the provider table and the error messages.
     * @param string $projectRoot Absolute path of the project root.
     *
     * @return int Exit code of {@see ProvidersService::run()}.
     */
    protected function handle(InputInterface $input, SymfonyOutputWriter $out, string $projectRoot): int
    {
        return (new ProvidersService())->run($projectRoot, $out);
    }
}
