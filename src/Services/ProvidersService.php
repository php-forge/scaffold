<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Services;

use PHPForge\Scaffold\Console\{ExitCode, Message, OutputWriter, Table};
use PHPForge\Scaffold\Scaffold\Lock\LockFile;

use function array_fill_keys;
use function array_keys;

/**
 * Lists scaffold providers recorded in `scaffold-lock.json` with their file counts.
 */
final class ProvidersService
{
    /**
     * Returns the number of tracked files of every provider recorded in `scaffold-lock.json` under `$projectRoot`.
     *
     * @param string $projectRoot Absolute path to the project root.
     *
     * @throws \RuntimeException when the lock file cannot be read.
     *
     * @return array<string, int> Map of provider package names to the number of files tracked for them.
     */
    public function getProviders(string $projectRoot): array
    {
        $data = (new LockFile($projectRoot))->read();

        // Providers whose manifests produced no tracked files still appear, with a count of zero.
        $providers = array_fill_keys(array_keys($data['providers']), 0);

        foreach ($data['files'] as $entry) {
            $name = $entry['provider'];
            $providers[$name] = ($providers[$name] ?? 0) + 1;
        }

        return $providers;
    }

    /**
     * Renders the providers table for the project rooted at `$projectRoot`.
     *
     * @param string $projectRoot Absolute path to the project root directory.
     * @param OutputWriter $out Output sink used for stdout writes.
     *
     * @throws \RuntimeException when the lock file cannot be read.
     *
     * @return int `0` on success.
     */
    public function run(string $projectRoot, OutputWriter $out): int
    {
        $providers = $this->getProviders($projectRoot);

        if ($providers === []) {
            $out->writeStdout(
                Message::PROVIDERS_NONE->getMessage(),
            );

            return ExitCode::Ok->value;
        }

        $rows = [];

        foreach ($providers as $name => $count) {
            $rows[] = [$name, (string) $count];
        }

        foreach (Table::render(['Provider', 'Files'], $rows) as $line) {
            $out->writeStdout(
                $line,
            );
        }

        return ExitCode::Ok->value;
    }
}
