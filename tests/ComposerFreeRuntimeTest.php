<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests;

use FilesystemIterator;
use LogicException;
use PHPForge\Scaffold\Tests\Provider\ComposerFreeRuntimeProvider;
use PHPForge\Scaffold\Tests\Support\{FileAssert, InspectionProject, RealComposer, TempDirectoryTrait};
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

use function dirname;
use function file_get_contents;
use function in_array;
use function preg_match_all;
use function str_replace;
use function strlen;
use function substr;

/**
 * Verifies that only the Composer adapters of `src/` reference Composer classes other than
 * `Composer\InstalledVersions`, and that the console commands never load them, so `vendor/bin/scaffold` runs in
 * consumers without `composer/composer`.
 *
 * {@see ComposerFreeRuntimeProvider} for test case data providers.
 */
#[Test]
final class ComposerFreeRuntimeTest
{
    use TempDirectoryTrait;

    /**
     * Files allowed to reference Composer's runtime classes, relative to `src/`.
     */
    private const array ADAPTERS = [
        'Console/ComposerIoWriter.php',
        'EventSubscriber.php',
        'Plugin.php',
        'Scaffold/Scaffolder.php',
    ];

    /**
     * Ensures every console command runs without loading a Composer IO or package class.
     */
    public function consoleCommandsNeverLoadComposerIoOrPackageClasses(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->installed(['p/a' => '1.0.0'])
            ->provider('p/a', ['a.txt' => "a\n", '.gitignore' => "/vendor\n"], ['.gitignore' => 'append']);

        $result = RealComposer::runPhp(
            [
                __DIR__ . '/Fixtures/runtime/guarded-cli.php',
                dirname(__DIR__) . '/vendor/autoload.php',
                $project->root,
            ],
            $this->tempDir,
        );

        Assert::same(
            $result->exitCode,
            0,
            "Exit code must be '0'; a loaded Composer class exits with '97': {$result->stderr}",
        );
        Assert::same(
            $result->stdout,
            '0,0,0,0,0',
            "Every command must exit with '0'.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/a.txt",
            "a\n",
            'Sync must have applied the provider.',
        );
    }

    /**
     * Ensures no file outside the Composer adapters references Composer runtime or adapter classes.
     */
    #[DataProvider(ComposerFreeRuntimeProvider::class . '::adapterOnlyReferences')]
    public function onlyTheComposerAdaptersMatch(string $pattern): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $relative => $content) {
            if (in_array($relative, self::ADAPTERS, true)) {
                continue;
            }

            preg_match_all($pattern, $content, $matches);

            foreach ($matches[0] ?? throw new LogicException("Pattern '{$pattern}' must compile.") as $symbol) {
                $offenders[] = "{$relative}: {$symbol}";
            }
        }

        Assert::same(
            $offenders,
            [],
            'No file outside the Composer adapters may match the pattern.',
        );
    }

    /**
     * Returns the contents of every PHP file under `src/`, keyed by path relative to `src/` with forward slashes.
     *
     * @return array<string, string>
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__) . '/src/';
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));

            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        Assert::notSame(
            $files,
            [],
            'Source files must be found.',
        );

        return $files;
    }
}
