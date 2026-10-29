<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console\Command;

use PHPForge\Scaffold\Console\SymfonyOutputWriter;
use PHPForge\Scaffold\Services\EjectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};

/**
 * Marks a file as ejected in `scaffold-lock.json` so scaffold stops managing it, without deleting it from disk.
 */
#[AsCommand(
    name: 'eject',
    description: "Marks a file as ejected in 'scaffold-lock.json' so scaffold stops managing it; it stays on disk.",
)]
final class EjectCommand extends AbstractScaffoldCommand
{
    /**
     * Declares the `file` argument and the `--yes` option.
     */
    protected function configure(): void
    {
        $this
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                "Destination path as recorded in 'scaffold-lock.json'.",
            )
            ->addOption(
                'yes',
                null,
                InputOption::VALUE_NONE,
                'Confirm the ejection without prompting.',
            );
    }

    /**
     * Ejects the given file, or previews the ejection without `--yes`.
     *
     * @param InputInterface $input Input with the `file` argument and the `--yes` option.
     * @param SymfonyOutputWriter $out Output sink that receives the ejection notice and the error messages.
     * @param string $projectRoot Absolute path of the project root.
     *
     * @return int Exit code of {@see EjectService::run()}.
     */
    protected function handle(InputInterface $input, SymfonyOutputWriter $out, string $projectRoot): int
    {
        /** @var string $file */
        $file = $input->getArgument('file');

        return (new EjectService())->run($projectRoot, $out, $file, (bool) $input->getOption('yes'));
    }
}
