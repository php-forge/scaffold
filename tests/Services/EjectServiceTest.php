<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Services;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Services\EjectService;
use PHPForge\Scaffold\Tests\Support\{BufferedOutputWriter, FileAssert, InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

use function file_get_contents;

/**
 * Unit tests for {@see EjectService} covering dry-run, confirmed ejection, and untracked-file error paths.
 */
#[Test]
#[Covers(EjectService::class)]
final class EjectServiceTest
{
    use TempDirectoryTrait;

    #[DataSet([false], 'preview')]
    #[DataSet([true], 'confirmed')]
    public function alreadyEjectedEntryReportsOnlyItsState(bool $confirmed): void
    {
        (new InspectionProject($this->tempDir))
            ->track('config/params.php', 'pkg/name', extra: ['ejected' => true]);

        $before = file_get_contents(
            "{$this->tempDir}/scaffold-lock.json",
        );

        $out = new BufferedOutputWriter();

        Assert::same(
            (new EjectService())->run($this->tempDir, $out, 'config/params.php', $confirmed),
            0,
            "Exit code must be '0' for an entry that is already ejected.",
        );
        Assert::same(
            $out->stdoutBuffer,
            Message::EJECT_ALREADY_EJECTED->getMessage('config/params.php') . PHP_EOL,
            'State line must be the only output.',
        );
        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $before,
            'Lock must not be rewritten.',
        );
    }

    public function confirmedEjectMarksTheEntryAndKeepsTheFile(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->file('config/params.php', "return [];\n")
            ->track('config/params.php', 'pkg/name');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new EjectService())->run($this->tempDir, $out, 'config/params.php', true),
            0,
            "Exit code must be '0' when the eject is confirmed.",
        );
        Assert::same(
            $out->stdoutBuffer,
            Message::EJECT_EJECTED->getMessage('config/params.php') . PHP_EOL,
            'Confirmation must be the only output.',
        );
        Assert::same(
            $project->lock()->read()['files']['config/params.php']['ejected'] ?? null,
            true,
            "Entry must be kept and marked 'ejected'.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/config/params.php",
            "return [];\n",
            'Eject must never touch the file on disk.',
        );
    }

    public function previewPrintsTheHintAndLeavesTheLockUntouched(): void
    {
        (new InspectionProject($this->tempDir))
            ->track('config/params.php', 'pkg/name');

        $before = file_get_contents(
            "{$this->tempDir}/scaffold-lock.json",
        );

        $out = new BufferedOutputWriter();

        Assert::same(
            (new EjectService())->run($this->tempDir, $out, 'config/params.php'),
            0,
            "Exit code of a preview must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            Message::EJECT_CONFIRMATION_REQUIRED->getMessage('config/params.php') . PHP_EOL,
            'Preview must be the only output.',
        );
        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $before,
            'Preview must not rewrite the lock.',
        );
    }

    public function untrackedFileIsAnError(): void
    {
        (new InspectionProject($this->tempDir))
            ->lock()
            ->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();

        Assert::same(
            (new EjectService())->run($this->tempDir, $out, 'missing.php', true),
            1,
            "Exit code must be '1' when the file is not tracked.",
        );
        Assert::same(
            $out->stderrBuffer,
            Message::COMMAND_FILE_NOT_TRACKED->getMessage('missing.php') . PHP_EOL,
            'Error must be the only stderr line.',
        );
        Assert::same(
            $out->stdoutBuffer,
            '',
            'Nothing may be written to stdout.',
        );
    }
}
