<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold\Modes;

use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Scaffold\Modes\{ApplyOutcome, ReplaceMode};
use PHPForge\Scaffold\Tests\Provider\ModeProvider;
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function file_get_contents;

/**
 * Unit tests for {@see ReplaceMode} overwriting an existing destination unless the user modified it.
 *
 * The shared mode behavior lives in {@see ModeContractTest}.
 *
 * {@see ModeProvider} for test case data providers.
 */
#[Test]
#[Covers(ReplaceMode::class)]
final class ReplaceModeTest
{
    use TempDirectoryTrait;

    #[DataProvider(ModeProvider::class . '::replaceDecisions')]
    public function appliesExistingDestinationAgainstLockHash(
        string|null $lockHash,
        string $existing,
        string $stub,
        ApplyOutcome $outcome,
        string $content,
        string $newHash,
        string|null $warning,
    ): void {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', $stub)
            ->file('output.txt', $existing);

        $result = (new ReplaceMode())->apply(
            new FileMapping(
                'output.txt',
                'stubs/output.txt',
                FileMode::Replace,
                'p/a',
                "{$this->tempDir}/vendor/p/a",
            ),
            $this->tempDir,
            $lockHash,
        );

        Assert::same(
            [$result->outcome, file_get_contents("{$this->tempDir}/output.txt"), $result->newHash, $result->warning],
            [$outcome, $content, $newHash, $warning],
            'Outcome, content, new hash and warning must match the decision.',
        );
    }
}
