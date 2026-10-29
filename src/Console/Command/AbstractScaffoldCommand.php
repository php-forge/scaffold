<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Console\Command;

use PHPForge\Scaffold\Config\{ProjectRootFinder, VendorDirResolver};
use PHPForge\Scaffold\Console\{Message, SymfonyOutputWriter};
use PHPForge\Scaffold\Scaffold\PathResolver;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function getcwd;
use function is_dir;
use function is_string;

/**
 * Runs a scaffold console command: validates its input, resolves the project root and reports a
 * {@see RuntimeException} of the command body as a `[scaffold]` error.
 */
abstract class AbstractScaffoldCommand extends Command
{
    /**
     * Runs the command body against the project root and returns its exit code.
     *
     * @param InputInterface $input Command input.
     * @param SymfonyOutputWriter $out Output sink of the command.
     * @param string $projectRoot Absolute path of the directory containing the project `composer.json`.
     *
     * @throws RuntimeException when the lock file or the project manifest cannot be read or written.
     *
     * @return int Exit code of the command.
     */
    abstract protected function handle(InputInterface $input, SymfonyOutputWriter $out, string $projectRoot): int;

    /**
     * Validates the input, resolves the project root and runs {@see self::handle()}.
     *
     * @param InputInterface $input Command input that may carry the global `--working-dir` option.
     * @param OutputInterface $output Console output that receives the command output and the error messages.
     *
     * @return int Exit code of {@see self::handle()}; `1` when the project root is not found or a runtime error is
     * reported; `2` when the input is invalid.
     */
    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = new SymfonyOutputWriter($output);

        if (!$this->validate($input, $out)) {
            return Command::INVALID;
        }

        $projectRoot = $this->resolveProjectRoot($input, $out);

        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        try {
            return $this->handle($input, $out, $projectRoot);
        } catch (RuntimeException $e) {
            $out->writeStderr(
                Message::SCAFFOLD_PREFIXED->getMessage($e->getMessage()),
            );

            return Command::FAILURE;
        }
    }

    /**
     * Returns whether the input is valid, writing the error to the error output when it is not.
     *
     * @param InputInterface $input Command input.
     * @param SymfonyOutputWriter $out Output sink that receives the error message.
     *
     * @return bool `true` when the command may run, `false` otherwise.
     */
    protected function validate(InputInterface $input, SymfonyOutputWriter $out): bool
    {
        return true;
    }

    /**
     * Returns the nearest directory containing the Composer manifest, searching up from `--working-dir` when given or
     * from the current working directory, or `null` after writing an error to the error output.
     *
     * @param InputInterface $input Command input that may carry the global `--working-dir` option.
     * @param SymfonyOutputWriter $out Output sink that receives the error message.
     *
     * @return string|null Absolute project root, or `null` when the start directory or the manifest cannot be found.
     */
    private function resolveProjectRoot(InputInterface $input, SymfonyOutputWriter $out): string|null
    {
        $workingDir = $input->hasOption('working-dir') ? $input->getOption('working-dir') : null;

        if (is_string($workingDir) && $workingDir !== '') {
            if (!is_dir($workingDir)) {
                $out->writeStderr(
                    Message::COMMAND_WORKING_DIR_NOT_FOUND->getMessage($workingDir),
                );

                return null;
            }

            $start = PathResolver::realpathOrFallback($workingDir);
        } else {
            $cwd = getcwd();

            if ($cwd === false) {
                $out->writeStderr(
                    Message::COMMAND_CWD_UNRESOLVABLE->getMessage(),
                );

                return null;
            }

            $start = $cwd;
        }

        $root = ProjectRootFinder::find($start);

        if ($root === null) {
            $out->writeStderr(
                Message::COMMAND_MANIFEST_NOT_FOUND->getMessage(VendorDirResolver::manifestName(), $start),
            );
        }

        return $root;
    }
}
