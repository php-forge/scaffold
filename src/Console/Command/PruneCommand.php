<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console\Command;

use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\SymfonyOutputWriter;
use PHPForge\Scaffold\Services\PruneService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputInterface, InputOption};

/**
 * Lists the files that no allowed provider ships anymore and, with `--force`, removes them from the lock and deletes
 * the ones that hold only provider content.
 */
#[AsCommand(
    name: 'prune',
    description: 'Lists orphaned files (no allowed provider ships them anymore); --force removes them.',
)]
final class PruneCommand extends AbstractScaffoldCommand
{
    /**
     * Declares the `--force`, `--delete-modified` and `--exit-code` options.
     */
    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Remove orphan entries from scaffold-lock.json and delete orphans that hold only provider content.',
            )
            ->addOption(
                'delete-modified',
                null,
                InputOption::VALUE_NONE,
                'With --force, also delete orphan files that hold consumer content (destructive).',
            )
            ->addOption(
                'exit-code',
                null,
                InputOption::VALUE_NONE,
                'Without --force, exit with 1 when orphaned files exist.',
            );
    }

    /**
     * Previews or prunes the orphaned entries.
     *
     * @param InputInterface $input Input with the `--force`, `--delete-modified` and `--exit-code` options.
     * @param SymfonyOutputWriter $out Output sink that receives the orphan report and the error messages.
     * @param string $projectRoot Absolute path of the project root.
     *
     * @return int Exit code of {@see PruneService::run()}.
     */
    protected function handle(InputInterface $input, SymfonyOutputWriter $out, string $projectRoot): int
    {
        return (new PruneService())->run(
            ProjectContext::create($projectRoot),
            $out,
            (bool) $input->getOption('force'),
            (bool) $input->getOption('delete-modified'),
            (bool) $input->getOption('exit-code'),
        );
    }
}
