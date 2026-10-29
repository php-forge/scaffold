<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Manifest;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Manifest\{FileMapping, FileMode, ManifestExpander};
use PHPForge\Scaffold\Merge\MergeStrategyKind;
use PHPForge\Scaffold\Tests\Provider\ManifestExpanderProvider;
use PHPForge\Scaffold\Tests\Support\TempDirectoryTrait;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\{DataProvider, DataSet};
use Testo\Expect;
use Testo\Test;

use function array_column;
use function array_map;
use function chmod;
use function dirname;
use function file_put_contents;
use function function_exists;
use function is_string;
use function posix_geteuid;

/**
 * Unit tests for {@see ManifestExpander} covering directory walking, exclusions, deduplication, and mode and merge
 * strategy resolution.
 *
 * {@see ManifestExpanderProvider} for test case data providers.
 */
#[Test]
#[Covers(ManifestExpander::class)]
final class ManifestExpanderTest
{
    use TempDirectoryTrait;

    /**
     * Ensures each mapping carries the provider name and path, the default mode and no merge strategy.
     */
    public function expandsIntoMappingsOfTheProvider(): void
    {
        $this->seed(['src/Bar.php', 'src/Foo.php']);

        Assert::equals(
            $this->expand(['src'], [], providerName: 'pkg/example'),
            [
                new FileMapping('src/Bar.php', 'src/Bar.php', FileMode::Replace, 'pkg/example', $this->tempDir),
                new FileMapping('src/Foo.php', 'src/Foo.php', FileMode::Replace, 'pkg/example', $this->tempDir),
            ],
            "Mappings must belong to 'pkg/example'.",
        );
    }

    /**
     * Ensures each file is mapped once, in entry order, after the default and manifest excludes.
     *
     * @param list<string> $seeds Files seeded under the provider root.
     * @param list<string|array{from: string, to: string}> $copy Manifest `copy` entries.
     * @param list<string> $exclude Manifest `exclude` patterns.
     * @param array<string, string> $expected Sources keyed by destination, in mapping order.
     */
    #[DataProvider(ManifestExpanderProvider::class . '::copyLists')]
    public function expandsTheCopyEntries(array $seeds, array $copy, array $exclude, array $expected): void
    {
        $this->seed($seeds);

        Assert::same(
            array_column($this->expand($copy, $exclude), 'source', 'destination'),
            $expected,
            'Sources keyed by destination must match.',
        );
    }

    /**
     * Ensures a `<dir>/**` exclude prunes the directory before the walk descends into it.
     *
     * @param list<string> $exclude Manifest `exclude` patterns.
     */
    #[DataSet(['vendor', []], 'default exclude')]
    #[DataSet(['secrets', ['secrets/**']], 'user exclude')]
    public function prunesExcludedDirectoriesBeforeDescent(string $directory, array $exclude): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new SkipTest(
                'chmod mode bits are not enforced on Windows.',
            );
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            throw new SkipTest(
                'Running as root bypasses chmod mode bits.',
            );
        }

        // 'chmod 0' denies 'opendir()', so the walk completes only when the directory is pruned before it is read.
        $this->seed(['src/main.php', "{$directory}/pkg/bogus.php"]);

        chmod("{$this->tempDir}/{$directory}", 0);

        try {
            $mappings = $this->expand(['.'], $exclude);
        } finally {
            chmod("{$this->tempDir}/{$directory}", 0o755);
        }

        Assert::same(
            array_column($mappings, 'destination'),
            ['src/main.php'],
            "Directory '{$directory}' must be pruned before descent.",
        );
    }

    /**
     * Ensures the mode and the merge strategy prefer an exact path, then the first matching glob, then the default.
     *
     * @param list<string> $seeds Files seeded under the provider root.
     * @param list<string|array{from: string, to: string}> $copy Manifest `copy` entries.
     * @param array<string, FileMode> $modes Manifest `modes` map.
     * @param array<string, MergeStrategyKind> $merge Manifest `merge` map.
     * @param array{mode: array<string, FileMode>, merge: array<string, MergeStrategyKind|null>} $expected Mode and
     * merge strategy keyed by destination.
     */
    #[DataProvider(ManifestExpanderProvider::class . '::attributes')]
    public function resolvesTheModeAndMergeStrategyOfEachDestination(
        array $seeds,
        array $copy,
        array $modes,
        array $merge,
        array $expected,
    ): void {
        $this->seed($seeds);

        $mappings = $this->expand($copy, [], $modes, $merge);

        Assert::same(
            [
                'mode' => array_column($mappings, 'mode', 'destination'),
                'merge' => array_column($mappings, 'mergeStrategy', 'destination'),
            ],
            $expected,
            'Mode and merge strategy keyed by destination must match.',
        );
    }

    /**
     * Ensures a `copy` entry missing on disk is rejected.
     */
    public function throwRuntimeExceptionWhenCopyEntryDoesNotExist(): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessage(Message::MANIFEST_COPY_ENTRY_NOT_FOUND->getMessage('missing-dir', $this->tempDir));

        $this->expand(['missing-dir'], []);
    }

    /**
     * Expands a manifest against the temp directory, normalising string `copy` entries to the `{from, to}` form.
     *
     * @param list<string|array{from: string, to: string}> $copy Manifest `copy` entries.
     * @param list<string> $exclude Manifest `exclude` patterns.
     * @param array<string, FileMode> $modes Manifest `modes` map.
     * @param array<string, MergeStrategyKind> $merge Manifest `merge` map.
     *
     * @return list<FileMapping> Expanded mappings.
     */
    private function expand(
        array $copy,
        array $exclude,
        array $modes = [],
        array $merge = [],
        string $providerName = 'pkg/test',
    ): array {
        return (new ManifestExpander())->expand(
            [
                'copy' => array_map(
                    static fn(string|array $entry): array => is_string($entry)
                        ? ['from' => $entry, 'to' => $entry]
                        : $entry,
                    $copy,
                ),
                'exclude' => $exclude,
                'modes' => $modes,
                'merge' => $merge,
            ],
            $this->tempDir,
            $providerName,
        );
    }

    /**
     * Writes empty files at the given paths under the temp directory, creating intermediate directories as needed.
     *
     * @param list<string> $relatives Paths relative to the temp directory.
     */
    private function seed(array $relatives): void
    {
        foreach ($relatives as $relative) {
            $absolute = "{$this->tempDir}/{$relative}";

            $this->ensureTestDirectory(dirname($absolute));

            file_put_contents(
                $absolute,
                '',
            );
        }
    }
}
