<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Config;

use PHPForge\Scaffold\Config\ProjectRootFinder;
use PHPForge\Scaffold\Tests\Support\TempDirectoryTrait;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

use function file_put_contents;
use function mkdir;
use function putenv;

/**
 * Unit tests for {@see ProjectRootFinder} walking up to the nearest Composer manifest.
 */
#[Test]
#[Covers(ProjectRootFinder::class)]
final class ProjectRootFinderTest
{
    use TempDirectoryTrait;

    /**
     * Ensures the nearest directory holding the manifest is returned without a trailing separator.
     *
     * @param string $start Start directory relative to the project root, with a trailing separator.
     */
    #[DataSet(['/src/deep/'], 'nested directory')]
    #[DataSet(['/'], 'project root')]
    public function findsTheNearestManifestWithoutTrailingSeparator(string $start): void
    {
        file_put_contents(
            "{$this->tempDir}/composer.json",
            '{}',
        );
        mkdir("{$this->tempDir}/src/deep", 0o777, true);

        Assert::same(
            ProjectRootFinder::find($this->tempDir . $start),
            $this->tempDir,
            'Search must stop at the manifest and drop the trailing separator.',
        );
    }

    /**
     * Ensures the manifest named by `COMPOSER` is searched instead of `composer.json`.
     */
    public function honorsComposerEnvironmentVariable(): void
    {
        file_put_contents(
            "{$this->tempDir}/composer.json",
            '{}',
        );
        mkdir("{$this->tempDir}/app", 0o777, true);
        file_put_contents(
            "{$this->tempDir}/app/custom.json",
            '{}',
        );

        putenv('COMPOSER=custom.json');

        try {
            $root = ProjectRootFinder::find("{$this->tempDir}/app");
        } finally {
            putenv('COMPOSER');
        }

        Assert::same(
            $root,
            "{$this->tempDir}/app",
            "Manifest named by 'COMPOSER' must be searched.",
        );
    }

    /**
     * Ensures the search yields `null` once it reaches the filesystem root.
     */
    public function returnsNullWhenFilesystemRootIsReached(): void
    {
        mkdir("{$this->tempDir}/a", 0o777, true);
        putenv('COMPOSER=scaffold-root-finder-never-exists.json');

        try {
            $fromProject = ProjectRootFinder::find("{$this->tempDir}/a");
            $fromRoot = ProjectRootFinder::find('/');
        } finally {
            putenv('COMPOSER');
        }

        Assert::null(
            $fromProject,
            "Missing manifest yields 'null'.",
        );
        Assert::null(
            $fromRoot,
            "Filesystem root without manifest yields 'null'.",
        );
    }
}
