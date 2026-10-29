<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold\Modes;

use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use PHPForge\Scaffold\Scaffold\Modes\{ApplyOutcome, PrependMode};
use PHPForge\Scaffold\Tests\Provider\ModeProvider;
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function file_get_contents;

/**
 * Unit tests for {@see PrependMode} placing the stub block before the destination content once.
 *
 * The shared mode behavior lives in {@see ModeContractTest}.
 *
 * {@see ModeProvider} for test case data providers.
 */
#[Test]
#[Covers(PrependMode::class)]
final class PrependModeTest
{
    use TempDirectoryTrait;

    #[DataProvider(ModeProvider::class . '::prependBlocks')]
    public function prependReturnsContentOrNullWhenBlockIsPresent(
        string $source,
        string|null $existing,
        string|null $expected,
    ): void {
        Assert::same(
            PrependMode::prepend($source, $existing),
            $expected,
            'Prepended content must match.',
        );
    }

    public function prependsStubToExistingDestination(): void
    {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', "# header\n")
            ->file('output.txt', 'EXISTING');

        $result = (new PrependMode())
            ->apply($this->mapping(), $this->tempDir, null);

        $path = "{$this->tempDir}/output.txt";

        Assert::same(
            [$result->outcome, file_get_contents($path), $result->newHash, $result->warning],
            [ApplyOutcome::Written, "# header\nEXISTING", Hasher::hash($path), null],
            "Destination must be 'Written' with the block before the existing content.",
        );
    }

    public function skipsDestinationHoldingTheBlock(): void
    {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', "# header\n")
            ->file('output.txt', "intro\n# header\nbody\n");

        $result = (new PrependMode())
            ->apply($this->mapping(), $this->tempDir, null);

        $path = "{$this->tempDir}/output.txt";

        Assert::same(
            [$result->outcome, file_get_contents($path), $result->newHash, $result->warning],
            [ApplyOutcome::Skipped, "intro\n# header\nbody\n", Hasher::hash($path), null],
            "Destination holding the block must be 'Skipped', untouched, with its current hash.",
        );
    }

    /**
     * Returns the prepend mapping of `output.txt` to the stub of the same name in the `p/a` provider.
     *
     * @return FileMapping Mapping of the destination.
     */
    private function mapping(): FileMapping
    {
        return new FileMapping(
            'output.txt',
            'stubs/output.txt',
            FileMode::Prepend,
            'p/a',
            "{$this->tempDir}/vendor/p/a",
        );
    }
}
