<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console\Command;

use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\{Message, SymfonyOutputWriter};
use PHPForge\Scaffold\Services\StatusService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputInterface, InputOption};

use function in_array;
use function is_string;

/**
 * Displays the state of every scaffold-tracked file against the disk and the current provider stubs.
 */
#[AsCommand(
    name: 'status',
    description: 'Displays the state of every scaffold-tracked file against the disk and the current provider stubs.',
)]
final class StatusCommand extends AbstractScaffoldCommand
{
    /**
     * Declares the `--format` and `--exit-code` options.
     */
    protected function configure(): void
    {
        $this
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: "table" or "json".',
                'table',
            )
            ->addOption(
                'exit-code',
                null,
                InputOption::VALUE_NONE,
                'Exit with 1 when any file is neither "synced" nor "ejected".',
            );
    }

    /**
     * Prints the status of the tracked files.
     *
     * @param InputInterface $input Input with the `--format` and `--exit-code` options.
     * @param SymfonyOutputWriter $out Output sink that receives the status table or JSON document and the error
     * messages.
     * @param string $projectRoot Absolute path of the project root.
     *
     * @return int Exit code of {@see StatusService::run()}.
     */
    protected function handle(InputInterface $input, SymfonyOutputWriter $out, string $projectRoot): int
    {
        return (new StatusService())->run(
            ProjectContext::create($projectRoot),
            $out,
            $input->getOption('format') === 'json',
            (bool) $input->getOption('exit-code'),
        );
    }

    /**
     * Returns whether `--format` is `table` or `json`.
     *
     * @param InputInterface $input Input with the `--format` option.
     * @param SymfonyOutputWriter $out Output sink that receives the error message.
     *
     * @return bool `true` when the format is known, `false` otherwise.
     */
    protected function validate(InputInterface $input, SymfonyOutputWriter $out): bool
    {
        $format = $input->getOption('format');

        if (!is_string($format) || !in_array($format, ['table', 'json'], true)) {
            $out->writeStderr(
                Message::STATUS_FORMAT_UNKNOWN->getMessage(is_string($format) ? $format : ''),
            );

            return false;
        }

        return true;
    }
}
