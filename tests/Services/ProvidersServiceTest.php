<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Services;

use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Services\ProvidersService;
use PHPForge\Scaffold\Tests\Provider\ProvidersServiceProvider;
use PHPForge\Scaffold\Tests\Support\{BufferedOutputWriter, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Unit tests for {@see ProvidersService} covering file-count aggregation and the providers table.
 *
 * {@see ProvidersServiceProvider} for test case data providers.
 */
#[Test]
#[Covers(ProvidersService::class)]
final class ProvidersServiceTest
{
    use TempDirectoryTrait;

    /**
     * @param array<string, string> $providers Provider versions keyed by name.
     * @param array<string, string> $files Provider names keyed by tracked destination.
     * @param array<string, int> $expected Expected file counts keyed by provider.
     */
    #[DataProvider(ProvidersServiceProvider::class . '::counts')]
    public function countsTheFilesOfEveryProvider(array $providers, array $files, array $expected): void
    {
        $this->writeLock($providers, $files);

        Assert::same(
            (new ProvidersService())->getProviders($this->tempDir),
            $expected,
            'File counts must aggregate per provider.',
        );
    }

    /**
     * @param array<string, string> $providers Provider versions keyed by name.
     * @param array<string, string> $files Provider names keyed by tracked destination.
     */
    #[DataProvider(ProvidersServiceProvider::class . '::tables')]
    public function rendersTheProvidersTable(array $providers, array $files, string $expected): void
    {
        $this->writeLock($providers, $files);

        $out = new BufferedOutputWriter();

        Assert::same(
            (new ProvidersService())->run($this->tempDir, $out),
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            $expected,
            'Table must be the only output.',
        );
        Assert::same(
            $out->stderrBuffer,
            '',
            'Nothing may be written to stderr.',
        );
    }

    /**
     * Writes a lock with the given providers and `replace` entries.
     *
     * @param array<string, string> $providers Provider versions keyed by name.
     * @param array<string, string> $files Provider names keyed by tracked destination.
     */
    private function writeLock(array $providers, array $files): void
    {
        $data = ['providers' => [], 'files' => []];

        foreach ($providers as $name => $version) {
            $data['providers'][$name] = ['version' => $version, 'path' => "vendor/{$name}"];
        }

        foreach ($files as $destination => $provider) {
            $data['files'][$destination] = [
                'hash' => 'sha256:abc',
                'provider' => $provider,
                'source' => "stubs/{$destination}",
                'mode' => 'replace',
            ];
        }

        (new LockFile($this->tempDir))->write($data);
    }
}
