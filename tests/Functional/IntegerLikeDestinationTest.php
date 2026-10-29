<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Functional;

use Composer\IO\BufferIO;
use Composer\Package\CompletePackage;
use PHPForge\Scaffold\Config\{InstalledPackages, ProjectConfig, ProjectContext};
use PHPForge\Scaffold\Console\{ComposerIoWriter, Message};
use PHPForge\Scaffold\Manifest\{ManifestExpander, ManifestLoader, ManifestSchema};
use PHPForge\Scaffold\Scaffold\FileApplier;
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Scaffold\{ScaffoldSummary, Scaffolder};
use PHPForge\Scaffold\Security\PathValidator;
use PHPForge\Scaffold\Services\{DiffService, EjectService, ReapplyService, StatusService};
use PHPForge\Scaffold\Tests\Support\{BufferedOutputWriter, FakeProjectBuilder, FileAssert, TempDirectoryTrait};
use Testo\Assert;
use Testo\Test;

use function array_keys;
use function file_put_contents;
use function json_decode;

/**
 * Functional tests for destinations that PHP converts to integer array keys (`"123"`, `"0"`) across {@see Scaffolder}
 * and the console services.
 */
#[Test]
final class IntegerLikeDestinationTest
{
    use TempDirectoryTrait;

    /**
     * Inline manifest of the provider `php-forge/numeric`.
     */
    private const array MANIFEST = ['copy' => ['123', '0'], 'modes' => ['0' => 'preserve']];

    /**
     * Ensures `diff` renders an edited integer-like destination under its own label.
     */
    public function diffRendersIntegerLikeDestination(): void
    {
        $builder = $this->scaffold();

        file_put_contents(
            "{$builder->getProjectRoot()}/123",
            "edited\n",
        );

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($this->context($builder), $out, '123'),
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            [$out->stdoutBuffer, $out->stderrBuffer],
            [
                Message::DIFF_PREVIEW_HEADER->getMessage(
                    '123',
                    'replace',
                    'php-forge/numeric',
                    Message::FRAGMENT_USER_MODIFIED_PREVIEW->getMessage(),
                ) . PHP_EOL
                . "--- a/123\n+++ b/123\n@@ -1 +1 @@\n-edited\n+one two three" . PHP_EOL,
                '',
            ],
            "Diff must label the destination '123' and report no error.",
        );
    }

    /**
     * Ensures `eject` marks only the integer-like destination as ejected and keeps the file.
     */
    public function ejectMarksIntegerLikeDestinationEjected(): void
    {
        $builder = $this->scaffold();

        Assert::same(
            (new EjectService())->run($builder->getProjectRoot(), new BufferedOutputWriter(), '123', true),
            0,
            "Exit code must be '0'.",
        );

        $files = (new LockFile($builder->getProjectRoot()))->read()['files'];

        Assert::same(
            [array_keys($files), $files['123']['ejected'] ?? false],
            [[0, 123], true],
            "Entry '123' must be 'ejected' and entry '0' must stay.",
        );
        FileAssert::fileExists(
            "{$builder->getProjectRoot()}/123",
            'Ejected file must stay on disk.',
        );
    }

    /**
     * Ensures `reapply` of the integer-like destination `0` restores only that file.
     */
    public function reapplyRestoresIntegerLikeDestination(): void
    {
        $builder = $this->scaffold();

        file_put_contents("{$builder->getProjectRoot()}/0", "edited\n");

        $out = new BufferedOutputWriter();

        Assert::same(
            (new ReapplyService())->run($this->context($builder), $out, '0', '', true),
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            Message::REAPPLY_APPLIED->getMessage('0') . PHP_EOL
            . Message::REAPPLY_SUMMARY->getMessage(
                '',
                1,
                Message::FRAGMENT_WRITTEN->getMessage(),
                0,
                0,
                '',
                0,
                Message::FRAGMENT_ERRORS->getMessage(),
            )
            . PHP_EOL,
            "Only the filtered file '0' must be reapplied.",
        );
        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/0",
            "zero\n",
            'Stub must be restored.',
        );
    }

    /**
     * Ensures the scaffolder writes integer-like destinations and applies the mode pattern keyed by one.
     */
    public function scaffoldWritesIntegerLikeDestinations(): void
    {
        $builder = $this->scaffold($io = new BufferIO());

        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/123",
            "one two three\n",
            "File '123' must be written.",
        );
        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/0",
            "zero\n",
            "File '0' must be written.",
        );
        Assert::same(
            $io->getOutput(),
            '',
            'No error may be reported.',
        );

        $files = (new LockFile($builder->getProjectRoot()))->read()['files'];

        Assert::same(
            [$files['0']['mode'] ?? null, $files['123']['mode'] ?? null],
            ['preserve', 'replace'],
            "Entry '0' must take the 'preserve' pattern and entry '123' the default mode.",
        );
    }

    /**
     * Ensures `status` reports integer-like destinations in the JSON report and the table.
     */
    public function statusReportsIntegerLikeDestinations(): void
    {
        $builder = $this->scaffold();
        $json = new BufferedOutputWriter();
        $table = new BufferedOutputWriter();

        (new StatusService())
            ->run($this->context($builder), $json, true);

        $report = json_decode($json->stdoutBuffer, true, flags: JSON_THROW_ON_ERROR);

        Assert::same(
            is_array($report) ? $report['files'] ?? null : null,
            [
                '0' => ['provider' => 'php-forge/numeric', 'mode' => 'preserve', 'status' => 'synced'],
                '123' => ['provider' => 'php-forge/numeric', 'mode' => 'replace', 'status' => 'synced'],
            ],
            "Files '123' and '0' must be 'synced'.",
        );
        Assert::same(
            (new StatusService())->run($this->context($builder), $table),
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            $table->stdoutBuffer,
            'File  Provider           Mode      Status' . PHP_EOL
            . '-----------------------------------------' . PHP_EOL
            . '0     php-forge/numeric  preserve  synced' . PHP_EOL
            . '123   php-forge/numeric  replace   synced' . PHP_EOL
            . Message::STATUS_SUMMARY->getMessage(
                2,
                Message::FRAGMENT_FILES->getMessage(),
                Message::FRAGMENT_COUNT->getMessage(2, 'synced'),
            ) . PHP_EOL,
            "Table must list '0' and '123'.",
        );
    }

    /**
     * Returns the services context of the project of `$builder`, whose vendor directory is a sibling of its root.
     */
    private function context(FakeProjectBuilder $builder): ProjectContext
    {
        $root = $builder->getProjectRoot();

        return new ProjectContext(
            $root,
            $builder->getVendorDir(),
            ProjectConfig::fromComposerJson("{$root}/composer.json"),
            InstalledPackages::detect($builder->getVendorDir(), $root),
        );
    }

    /**
     * Scaffolds the provider `php-forge/numeric`, which ships the destinations `123` and `0`, into a fake project and
     * returns its builder.
     *
     * @param BufferIO $io IO of the run.
     */
    private function scaffold(BufferIO $io = new BufferIO()): FakeProjectBuilder
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $extra = ['scaffold' => ['allowed-packages' => ['php-forge/numeric']]];

        $config = ProjectConfig::fromExtra($extra);

        $provider = new CompletePackage('php-forge/numeric', '1.0.0.0', '1.0.0');

        $builder->createComposerJson(['extra' => $extra]);
        $builder->createStubFile(
            'php-forge/numeric',
            'composer.json',
            json_encode(['extra' => ['scaffold' => self::MANIFEST]], JSON_THROW_ON_ERROR),
        );
        $builder->createStubFile('php-forge/numeric', '123', "one two three\n");
        $builder->createStubFile('php-forge/numeric', '0', "zero\n");
        $provider->setExtra(['scaffold' => self::MANIFEST]);

        (new Scaffolder(
            new ManifestLoader(new ManifestSchema(), new ManifestExpander()),
            new FileApplier($config, new PathValidator(), new ComposerIoWriter($io)),
            new LockFile($builder->getProjectRoot()),
            new ComposerIoWriter($io),
            new ScaffoldSummary(),
        ))->scaffold($config, [$provider], $builder->getProjectRoot(), $builder->getVendorDir());

        return $builder;
    }
}
