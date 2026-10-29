<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold\Modes;

use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Merge\MergeStrategyKind;
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use PHPForge\Scaffold\Scaffold\Modes\{AppendMode, ApplyOutcome};
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function file_get_contents;

/**
 * Unit tests for {@see AppendMode} merging the stub into an existing destination with its merge strategy.
 *
 * The strategies own the merge rules; the shared mode behavior lives in {@see ModeContractTest}.
 */
#[Test]
#[Covers(AppendMode::class)]
final class AppendModeTest
{
    use TempDirectoryTrait;

    public function mergesMissingLinesIntoExistingDestination(): void
    {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', "alpha\nbeta\ngamma\n")
            ->file('output.txt', "alpha\nbeta\n");

        $result = (new AppendMode())
            ->apply($this->mapping('output.txt'), $this->tempDir, 'sha256:some-recorded-hash');

        $path = "{$this->tempDir}/output.txt";

        Assert::same(
            [$result->outcome, file_get_contents($path), $result->newHash, $result->warning],
            [ApplyOutcome::Written, "alpha\nbeta\n\ngamma\n", Hasher::hash($path), null],
            "Merged destination must be 'Written' with the missing line after a blank separator.",
        );
    }

    public function skipsDestinationHoldingEveryProviderLine(): void
    {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', "alpha\nbeta\n")
            ->file('output.txt', "beta\nalpha\n");

        $result = (new AppendMode())
            ->apply($this->mapping('output.txt'), $this->tempDir, null);

        $path = "{$this->tempDir}/output.txt";

        Assert::same(
            [$result->outcome, file_get_contents($path), $result->newHash, $result->warning],
            [ApplyOutcome::Skipped, "beta\nalpha\n", Hasher::hash($path), null],
            "Complete destination must be 'Skipped', byte-identical, with its current hash.",
        );
    }

    public function usesManifestMergeStrategyOverBasenameDefault(): void
    {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', '.gitignore', "# b\nx\n")
            ->file('.gitignore', "# a\nx\n");

        $result = (new AppendMode())->apply(
            $this->mapping('.gitignore', MergeStrategyKind::Text),
            $this->tempDir,
            null,
        );

        Assert::same(
            [$result->outcome, file_get_contents("{$this->tempDir}/.gitignore")],
            [ApplyOutcome::Written, "# a\nx\n\n# b\n"],
            "Strategy 'text' must replace the 'list' default and treat the comment as content.",
        );
    }

    /**
     * Returns the append mapping of `$destination` to the stub of the same name in the `p/a` provider.
     *
     * @param string $destination Destination path relative to the project root.
     * @param MergeStrategyKind|null $mergeStrategy Manifest merge strategy, or `null` for the basename default.
     *
     * @return FileMapping Mapping of the destination.
     */
    private function mapping(string $destination, MergeStrategyKind|null $mergeStrategy = null): FileMapping
    {
        return new FileMapping(
            $destination,
            "stubs/{$destination}",
            FileMode::Append,
            'p/a',
            "{$this->tempDir}/vendor/p/a",
            $mergeStrategy,
        );
    }
}
