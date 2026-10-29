<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console\Command;

use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\SymfonyOutputWriter;
use PHPForge\Scaffold\Services\ReapplyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};

/**
 * Reapplies provider stubs to tracked files; `--force` also overwrites ejected, adopted, user-modified, `preserve`,
 * `append` and `prepend` files.
 */
#[AsCommand(
    name: 'reapply',
    description: 'Re-applies scaffold stubs to the project, optionally overwriting user-modified files.',
)]
final class ReapplyCommand extends AbstractScaffoldCommand
{
    /**
     * Declares the optional `file` argument and the `--force`, `--provider`, `--dry-run` and `--exit-code` options.
     */
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
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print the diff of every file that would change without writing anything.',
            )
            ->addOption(
                'exit-code',
                null,
                InputOption::VALUE_NONE,
                'With --dry-run, exit with 1 when any file would change.',
            );
    }

    /**
     * Reapplies the matching tracked files.
     *
     * @param InputInterface $input Input with the `file` argument and the `--force`, `--provider`, `--dry-run` and
     * `--exit-code` options.
     * @param SymfonyOutputWriter $out Output sink that receives the reapply report and the error messages.
     * @param string $projectRoot Absolute path of the project root.
     *
     * @return int Exit code of {@see ReapplyService::run()}.
     */
    protected function handle(InputInterface $input, SymfonyOutputWriter $out, string $projectRoot): int
    {
        /** @var string $file */
        $file = $input->getArgument('file');

        /** @var string $provider */
        $provider = $input->getOption('provider');

        return (new ReapplyService())->run(
            ProjectContext::create($projectRoot),
            $out,
            $file,
            $provider,
            (bool) $input->getOption('force'),
            (bool) $input->getOption('dry-run'),
            (bool) $input->getOption('exit-code'),
        );
    }
}
