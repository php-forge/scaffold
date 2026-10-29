<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console\Command;

use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\{Message, SymfonyOutputWriter};
use PHPForge\Scaffold\Services\DiffService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};

use function is_string;

/**
 * Shows what applying the provider would change in a tracked file, or in every tracked file, as a unified diff.
 */
#[AsCommand(
    name: 'diff',
    description: 'Shows what applying the provider would change in a tracked file, as a git-style unified diff.',
)]
final class DiffCommand extends AbstractScaffoldCommand
{
    /**
     * Declares the optional `file` argument and the `--all` and `--exit-code` options.
     */
    protected function configure(): void
    {
        $this
            ->addArgument(
                'file',
                InputArgument::OPTIONAL,
                "Destination path as recorded in 'scaffold-lock.json'.",
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Diff every tracked, non-ejected file in lock order.',
            )
            ->addOption(
                'exit-code',
                null,
                InputOption::VALUE_NONE,
                'Exit with 1 when applying would change a file.',
            );
    }

    /**
     * Renders the diff of the given file, or of every file with `--all`.
     *
     * @param InputInterface $input Input with the `file` argument and the `--all` and `--exit-code` options.
     * @param SymfonyOutputWriter $out Output sink that receives the diff and the error messages.
     * @param string $projectRoot Absolute path of the project root.
     *
     * @return int Exit code of {@see DiffService::run()}.
     */
    protected function handle(InputInterface $input, SymfonyOutputWriter $out, string $projectRoot): int
    {
        $file = $input->getArgument('file');

        return (new DiffService())->run(
            ProjectContext::create($projectRoot),
            $out,
            is_string($file) && $file !== '' ? $file : null,
            $out->isDecorated(),
            (bool) $input->getOption('exit-code'),
        );
    }

    /**
     * Returns whether exactly one of `file` and `--all` is given.
     *
     * @param InputInterface $input Input with the `file` argument and the `--all` option.
     * @param SymfonyOutputWriter $out Output sink that receives the error message.
     *
     * @return bool `true` when exactly one target is given, `false` otherwise.
     */
    protected function validate(InputInterface $input, SymfonyOutputWriter $out): bool
    {
        $file = $input->getArgument('file');

        if ((is_string($file) && $file !== '') === (bool) $input->getOption('all')) {
            $out->writeStderr(
                Message::DIFF_TARGET_AMBIGUOUS->getMessage(),
            );

            return false;
        }

        return true;
    }
}
