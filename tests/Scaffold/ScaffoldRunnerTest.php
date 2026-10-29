<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold;

use PHPForge\Scaffold\Config\{ProjectConfig, ProviderPackage};
use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Manifest\{ManifestExpander, ManifestLoader, ManifestSchema};
use PHPForge\Scaffold\Scaffold\{FileApplier, ScaffoldRunner, ScaffoldSummary};
use PHPForge\Scaffold\Scaffold\Lock\{Hasher, LockFile};
use PHPForge\Scaffold\Security\PathValidator;
use PHPForge\Scaffold\Tests\Provider\ScaffoldRunnerProvider;
use PHPForge\Scaffold\Tests\Support\{BufferedOutputWriter, FileAssert, InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function array_key_exists;
use function file_put_contents;
use function implode;
use function ltrim;
use function realpath;
use function str_replace;

/**
 * Unit tests for {@see ScaffoldRunner} applying provider files without Composer's IO and package classes.
 *
 * {@see ScaffoldRunnerProvider} for test case data providers.
 */
#[Test]
#[Covers(ScaffoldRunner::class)]
final class ScaffoldRunnerTest
{
    use TempDirectoryTrait;

    public function adoptedReplaceFileWithoutStubHashIsSkippedWithoutConflict(): void
    {
        (new InspectionProject($this->tempDir))
            ->provider('p/a', ['a.txt' => "provider\n"])
            ->file('a.txt', "mine\n")
            ->track('a.txt', 'p/a', 'replace', ['stub-hash' => null]);

        $lock = new LockFile($this->tempDir);

        $data = $lock->read();

        $data['files']['a.txt']['adopted'] = true;

        $lock->write($data);

        $summary = $this->runner(
            ['p/a'],
            new BufferedOutputWriter())->run(
                new ProjectConfig(['p/a']),
                ['p/a' => new ProviderPackage('p/a', '1.0.0', "{$this->tempDir}/vendor/p/a")],
                $this->tempDir,
            );

        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "mine\n",
            'Adopted file must stay.',
        );
        Assert::same(
            self::counters($summary),
            ['written' => 0, 'unchanged' => 0, 'warnings' => 1, 'errors' => 0, 'conflict' => 0, 'outdated' => 0],
            "Skip must count one warning and no conflict without a recorded 'stub-hash'.",
        );
    }

    public function appendDestinationHoldingTheBlockIsNotAdopted(): void
    {
        (new InspectionProject($this->tempDir))
            ->provider('p/a', ['a.txt' => "block\n"], ['a.txt' => 'append'])
            ->file('a.txt', "mine\nblock\n");

        $summary = $this->runner(
            ['p/a'],
            new BufferedOutputWriter())->run(
                new ProjectConfig(['p/a']),
                ['p/a' => new ProviderPackage('p/a', '1.0.0', "{$this->tempDir}/vendor/p/a")],
                $this->tempDir,
            );

        Assert::same(
            [
                array_key_exists(
                    'adopted',
                    (new LockFile($this->tempDir))->read()['files']['a.txt'] ?? []
                ),
                $summary->unchanged,
            ],
            [false, 1],
            "Entry must be recorded without 'adopted' and count as 'unchanged'.",
        );
    }

    public function appliesProvidersReadFromComposerJsonAndFromExtra(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->provider('p/a', ['a.txt' => "a\n"])
            ->provider('p/b', ['b.txt' => "b\n"]);

        $out = new BufferedOutputWriter();

        $summary = $this->runner(
            ['p/a', 'p/b'], $out)
            ->run(
                new ProjectConfig(['p/a', 'p/b']),
                [
                    'p/a' => new ProviderPackage(
                        'p/a',
                        '1.0.0',
                        "{$this->tempDir}/vendor/p/a",
                    ),
                    'p/b' => new ProviderPackage(
                        'p/b',
                        '2.0.0',
                        "{$this->tempDir}/vendor/p/b",
                        ['scaffold' => ['copy' => [['from' => 'stubs/b.txt', 'to' => 'b.txt']]]],
                    ),
                ],
                $this->tempDir,
            );

        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "a\n",
            "Provider read from 'composer.json' must apply.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/b.txt",
            "b\n",
            "Provider read from 'extra' must apply.",
        );
        Assert::same(
            $summary->written,
            2,
            "Both files must count as 'written'.",
        );
        Assert::same(
            $project->lock()->read()['providers'],
            [
                'p/a' => ['version' => '1.0.0', 'path' => 'vendor/p/a'],
                'p/b' => ['version' => '2.0.0', 'path' => 'vendor/p/b'],
            ],
            'Providers must be recorded with relative paths.',
        );
        Assert::same(
            $out->stderrBuffer,
            '',
            'No warning expected.',
        );
    }

    public function ejectedFileIsCountedAndTheNextFileStillApplies(): void
    {
        (new InspectionProject($this->tempDir))
            ->provider('p/a', ['a.txt' => "provider\n", 'b.txt' => "b\n"])
            ->file('a.txt', "mine\n")
            ->track('a.txt', 'p/a', 'replace', ['ejected' => true]);

        $summary = $this->runner(
            ['p/a'],
            new BufferedOutputWriter())->run(
                new ProjectConfig(['p/a']),
                ['p/a' => new ProviderPackage('p/a', '1.0.0', "{$this->tempDir}/vendor/p/a")],
                $this->tempDir,
            );

        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "mine\n",
            'Ejected file must stay.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/b.txt",
            "b\n",
            'Next file must apply.',
        );
        Assert::same(
            self::counters($summary),
            ['written' => 1, 'unchanged' => 1, 'warnings' => 0, 'errors' => 0, 'conflict' => 0, 'outdated' => 0],
            "Ejected file must count as 'unchanged' and the next one as 'written'.",
        );
    }

    public function forceOverwritesUserModifiedReplaceFile(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->provider('p/a', ['a.txt' => "provider\n"])
            ->file('a.txt', "provider\n")
            ->track('a.txt', 'p/a');

        file_put_contents(
            "{$this->tempDir}/a.txt",
            "mine\n",
        );

        $providers = [
            'p/a' => new ProviderPackage('p/a', '1.0.0', "{$this->tempDir}/vendor/p/a"),
        ];

        $out = new BufferedOutputWriter();

        $this->runner(
            ['p/a'],
            $out)->run(new ProjectConfig(['p/a']),
            $providers,
            $this->tempDir,
        );

        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "mine\n",
            'Without force the edit must stay.',
        );
        Assert::true(
            str_contains($out->stderrBuffer, Message::SCAFFOLD_USER_MODIFIED_SKIPPED->getMessage('a.txt')),
            'Skipped user-modified file must be warned.',
        );

        $forced = new BufferedOutputWriter();

        $summary = $this->runner(
            ['p/a'],
            $forced)->run(new ProjectConfig(['p/a']),
            $providers,
            $this->tempDir,
            true,
        );

        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "provider\n",
            'Force must overwrite the edit.',
        );
        Assert::same(
            [$forced->stderrBuffer, $summary->written, $project->lock()->read()['files']['a.txt']['hash'] ?? null],
            ['', 1, Hasher::hash("{$this->tempDir}/a.txt")],
            "Forced overwrite must be silent, count as 'written' and record the new hash.",
        );
    }

    public function forceRewritesAdoptedReplaceFileAndClearsTheFlag(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->provider('p/a', ['a.txt' => "provider\n"])
            ->file('a.txt', "mine\n")
            ->track('a.txt', 'p/a', 'replace', ['adopted' => 'yes']);

        $data = $project->lock()->read();

        $data['files']['a.txt']['adopted'] = true;

        $project->lock()->write($data);

        $this->runner(
            ['p/a'],
            new BufferedOutputWriter())->run(
                new ProjectConfig(['p/a']),
                ['p/a' => new ProviderPackage('p/a', '1.0.0', "{$this->tempDir}/vendor/p/a")],
                $this->tempDir,
                true,
            );

        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "provider\n",
            'Adopted file must be replaced.',
        );
        Assert::false(
            array_key_exists(
                'adopted',
                (new LockFile($this->tempDir))->read()['files']['a.txt'] ?? [],
            ),
            "Flag 'adopted' must be cleared.",
        );
    }

    public function metapackageAloneLeavesNoLockFile(): void
    {
        $this->runner(
            ['p/meta'],
            new BufferedOutputWriter())->run(
                new ProjectConfig(['p/meta']),
                ['p/meta' => new ProviderPackage('p/meta', '1.0.0', null, [])],
                $this->tempDir,
            );

        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/scaffold-lock.json",
            'Nothing must be recorded.',
        );
    }

    public function metapackageWithoutInstallPathIsSkippedWithWarning(): void
    {
        (new InspectionProject($this->tempDir))->provider('p/a', ['a.txt' => "a\n"]);

        $out = new BufferedOutputWriter();

        $summary = $this->runner(
            ['p/meta', 'p/a'], $out)->run(
                new ProjectConfig(['p/meta', 'p/a']),
                [
                    'p/meta' => new ProviderPackage('p/meta', '1.0.0', null, []),
                    'p/a' => new ProviderPackage('p/a', '1.0.0', "{$this->tempDir}/vendor/p/a"),
                ],
                $this->tempDir,
            );

        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "a\n",
            'Next provider must still apply.',
        );
        Assert::same(
            [$out->stderrBuffer, $summary->warnings, $summary->written],
            [Message::SCAFFOLD_PROVIDER_INSTALL_PATH_MISSING->getMessage('p/meta') . PHP_EOL, 1, 1],
            "Metapackage must be reported as one warning and the next file counted as 'written'.",
        );
    }

    public function orphanCountAccumulatesAcrossRunsSharingTheSummary(): void
    {
        (new InspectionProject($this->tempDir))
            ->file('old.txt', "old\n")->track('old.txt', 'p/gone');

        $runner = $this->runner(
            ['p/gone'],
            new BufferedOutputWriter(),
        );

        $runner->run(new ProjectConfig(['p/gone']), [], $this->tempDir);

        $summary = $runner->run(new ProjectConfig(['p/gone']), [], $this->tempDir);

        Assert::same(
            $summary->orphan,
            2,
            'Counters of a shared summary must add up.',
        );
    }

    public function orphansAreListedWithLimitAndRemainder(): void
    {
        $project = (new InspectionProject($this->tempDir))->provider('p/a', ['a.txt' => "a\n"]);

        foreach (
            [
                'old1',
                'old10',
                'old11',
                'old12',
                'old2',
                'old3',
                'old4',
                'old5',
                'old6',
                'old7',
                'old8',
                'old9',
            ] as $name) {
            $project->file("{$name}.txt", "old\n")->track("{$name}.txt", 'p/a');
        }

        $out = new BufferedOutputWriter();

        $summary = $this->runner(
            ['p/a'],
            $out)->run(
                new ProjectConfig(['p/a']),
                ['p/a' => new ProviderPackage('p/a', '1.0.0', "{$this->tempDir}/vendor/p/a")],
                $this->tempDir,
            );

        Assert::same(
            $out->stderrBuffer,
            implode(
                PHP_EOL,
                [
                    Message::SCAFFOLD_ORPHAN_HEADER->getMessage(12, Message::FRAGMENT_FILES->getMessage()),
                    Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old1.txt', 'p/a'),
                    Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old10.txt', 'p/a'),
                    Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old11.txt', 'p/a'),
                    Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old12.txt', 'p/a'),
                    Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old2.txt', 'p/a'),
                    Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old3.txt', 'p/a'),
                    Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old4.txt', 'p/a'),
                    Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old5.txt', 'p/a'),
                    Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old6.txt', 'p/a'),
                    Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old7.txt', 'p/a'),
                    Message::SCAFFOLD_ORPHAN_MORE->getMessage(2),
                    '',
                ],
            ),
            'Header, the first ten orphans and the remainder line expected.',
        );
        Assert::same(
            $summary->orphan,
            12,
            'Summary must count every orphan.',
        );
        FileAssert::fileExists(
            "{$this->tempDir}/old12.txt",
            'Orphans must never be deleted.',
        );
        Assert::true(
            array_key_exists('old12.txt', $project->lock()->read()['files']),
            'Orphan lock entries must stay.',
        );
    }

    public function orphansAreReportedWhenNoProviderShipsAnything(): void
    {
        (new InspectionProject($this->tempDir))
            ->file('old.txt', "old\n")->track('old.txt', 'p/gone');

        $out = new BufferedOutputWriter();

        $summary = $this->runner(
            ['p/gone'],
            $out)->run(new ProjectConfig(['p/gone']),
            [],
            $this->tempDir,
        );

        Assert::same(
            [$out->stderrBuffer, $summary->orphan],
            [
                Message::SCAFFOLD_PROVIDER_NOT_INSTALLED->getMessage('p/gone') . PHP_EOL
                . Message::SCAFFOLD_ORPHAN_HEADER->getMessage(1, Message::FRAGMENT_FILE->getMessage()) . PHP_EOL
                . Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('old.txt', 'p/gone') . PHP_EOL,
                1,
            ],
            'Orphan must be reported and counted without mappings.',
        );
    }

    public function providerWithoutMappingsIsWarnedAndTheNextProviderStillApplies(): void
    {
        (new InspectionProject($this->tempDir))
            ->provider('p/a', ['a.txt' => "a\n"]);

        $out = new BufferedOutputWriter();

        $summary = $this->runner(['p/empty', 'p/a'], $out)
            ->run(
                new ProjectConfig(['p/empty', 'p/a']),
                [
                    'p/empty' => new ProviderPackage('p/empty', '1.0.0', "{$this->tempDir}/vendor/p/empty", []),
                    'p/a' => new ProviderPackage('p/a', '1.0.0', "{$this->tempDir}/vendor/p/a"),
                ],
                $this->tempDir,
            );

        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "a\n",
            'Next provider must apply.',
        );
        Assert::same(
            [$out->stderrBuffer, self::counters($summary)],
            [
                Message::SCAFFOLD_PROVIDER_MAPPINGS_EMPTY->getMessage('p/empty') . PHP_EOL,
                ['written' => 1, 'unchanged' => 0, 'warnings' => 1, 'errors' => 0, 'conflict' => 0, 'outdated' => 0],
            ],
            "Empty provider must be warned once and the next file must count as 'written'.",
        );
    }

    #[DataProvider(ScaffoldRunnerProvider::class . '::providerPaths')]
    public function recordsProviderPathRelativeToProjectRoot(
        string $installPath,
        string $projectRoot,
        string $expected,
    ): void {
        $installPath = $this->expand($installPath);

        (new InspectionProject($installPath))
            ->file('stubs/a.txt', "a\n");

        $out = new BufferedOutputWriter();

        // The applier allows no provider, so no file is written outside the temporary directory.
        (new ScaffoldRunner(
            new ManifestLoader(new ManifestSchema(), new ManifestExpander()),
            new FileApplier(new ProjectConfig([]), new PathValidator(), $out),
            new LockFile($this->tempDir),
            $out,
            new ScaffoldSummary(),
        ))->run(
            new ProjectConfig(['p/a']),
            [
                'p/a' => new ProviderPackage(
                    'p/a',
                    '1.0.0',
                    $installPath,
                    ['scaffold' => ['copy' => [['from' => 'stubs/a.txt', 'to' => 'a.txt']]]],
                ),
            ],
            $this->expand($projectRoot),
        );

        Assert::same(
            (new LockFile($this->tempDir))->read()['providers'],
            ['p/a' => ['version' => '1.0.0', 'path' => $this->expand($expected)]],
            'Recorded provider path must match.',
        );
    }

    /**
     * Returns the counters of `$summary` that a run updates, except the orphan count.
     *
     * @return array{written: int, unchanged: int, warnings: int, errors: int, conflict: int, outdated: int}
     */
    private static function counters(ScaffoldSummary $summary): array
    {
        return [
            'written' => $summary->written,
            'unchanged' => $summary->unchanged,
            'warnings' => $summary->warnings,
            'errors' => $summary->errors,
            'conflict' => $summary->conflict,
            'outdated' => $summary->outdated,
        ];
    }

    /**
     * Replaces the `{tmp}`, `{real}` and `{rel}` placeholders with the temporary directory, its canonical path and
     * that path without its leading separator.
     *
     * @param string $path Path with placeholders.
     *
     * @return string Path with the placeholders replaced.
     */
    private function expand(string $path): string
    {
        $real = (string) realpath($this->tempDir);

        return str_replace(['{tmp}', '{real}', '{rel}'], [$this->tempDir, $real, ltrim($real, '/')], $path);
    }

    /**
     * Builds a runner for the project in the temporary directory.
     *
     * @param list<string> $allowed Providers allowed to write files.
     * @param BufferedOutputWriter $out Output sink of the messages.
     *
     * @return ScaffoldRunner Runner configured for the test.
     */
    private function runner(array $allowed, BufferedOutputWriter $out): ScaffoldRunner
    {
        return new ScaffoldRunner(
            new ManifestLoader(new ManifestSchema(), new ManifestExpander()),
            new FileApplier(new ProjectConfig($allowed), new PathValidator(), $out),
            new LockFile($this->tempDir),
            $out,
            new ScaffoldSummary(),
        );
    }
}
