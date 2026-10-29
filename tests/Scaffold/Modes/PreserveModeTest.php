<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold\Modes;

use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use PHPForge\Scaffold\Scaffold\Modes\{ApplyOutcome, PreserveMode};
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

use function file_get_contents;

/**
 * Unit tests for {@see PreserveMode} never overwriting an existing destination.
 *
 * The shared mode behavior lives in {@see ModeContractTest}.
 */
#[Test]
#[Covers(PreserveMode::class)]
final class PreserveModeTest
{
    use TempDirectoryTrait;

    #[DataSet([null], 'without lock hash')]
    #[DataSet(['sha256:some-recorded-hash'], 'with lock hash')]
    public function skipsExistingDestination(string|null $lockHash): void
    {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', 'stub content')
            ->file('output.txt', 'user content');

        $result = (new PreserveMode())->apply(
            new FileMapping(
                'output.txt',
                'stubs/output.txt',
                FileMode::Preserve,
                'p/a',
                "{$this->tempDir}/vendor/p/a",
            ),
            $this->tempDir,
            $lockHash,
        );

        $path = "{$this->tempDir}/output.txt";

        Assert::same(
            [$result->outcome, file_get_contents($path), $result->newHash, $result->warning],
            [ApplyOutcome::Skipped, 'user content', Hasher::hash($path), null],
            "Existing destination must be 'Skipped', untouched, with its current hash and no warning.",
        );
    }
}
