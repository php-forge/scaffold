<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Services;

use Closure;
use PHPForge\Scaffold\Config\ProjectContext;
use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Services\StatusService;
use PHPForge\Scaffold\Tests\Provider\StatusServiceProvider;
use PHPForge\Scaffold\Tests\Support\{BufferedOutputWriter, FileAssert, InspectionProject, TempDirectoryTrait};
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

use function array_keys;
use function array_map;
use function implode;
use function json_decode;
use function str_repeat;
use function symlink;
use function unlink;

/**
 * Unit tests for {@see StatusService} covering the state model per mode, provider drift, the JSON report and the exit
 * codes.
 *
 * {@see StatusServiceProvider} for test case data providers.
 */
#[Test]
#[Covers(StatusService::class)]
final class StatusServiceTest
{
    use TempDirectoryTrait;

    public function driftLineReportsLockAndInstalledVersions(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a', version: '0.2.0')
            ->installed(['p/a' => '0.3.0']);

        $out = new BufferedOutputWriter();

        (new StatusService())
            ->run($project->context(), $out);

        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::STATUS_PROVIDER_DRIFT->getMessage('p/a', '0.2.0', '0.3.0') . PHP_EOL,
            'Drift must be the only stderr line.',
        );
    }

    public function ejectedEntryOfProviderNoLongerAllowedProducesNoNotice(): void
    {
        $this->project()
            ->file('e.txt', "mine\n")
            ->track('e.txt', 'p/denied', extra: ['ejected' => true]);

        $out = new BufferedOutputWriter();

        (new StatusService())
            ->run(ProjectContext::create($this->tempDir), $out);

        Assert::same(
            $out->stderrBuffer,
            '',
            'Ejected entries belong to the user.',
        );
    }

    public function entryWithUnreadableDestinationIsError(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a');

        $this->restrictFileAccess("{$this->tempDir}/a.txt", 0o000);

        Assert::same(
            $this->states($project),
            ['a.txt' => 'error'],
            "Unreadable destination must be in the 'error' state.",
        );
    }

    public function expectedFilesWithoutLockEntryArePending(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n", 'new.txt' => "new\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new StatusService())->run($project->context(), $out, false, true),
            1,
            "Exit code must be '1' for pending files with '--exit-code'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            'File     Provider  Mode     Status' . PHP_EOL
            . str_repeat('-', 35) . PHP_EOL
            . 'a.txt    p/a       replace  synced' . PHP_EOL
            . 'new.txt  p/a       replace  pending' . PHP_EOL
            . self::summary(2, ['synced' => 1, 'pending' => 1]) . PHP_EOL,
            'Untracked provider file must be listed as pending and counted.',
        );
    }
    public function jsonReportContainsFilesProvidersAndSummaryOnly(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n", 'b.txt' => "b\n"])
            ->file('a.txt', "a\n")
            ->file('b.txt', "b\n")
            ->track('a.txt', 'p/a', version: '0.2.0')
            ->track('b.txt', 'p/a', version: '0.2.0')
            ->file('b.txt', "mine\n")
            ->installed(['p/a' => '0.3.0']);

        $out = new BufferedOutputWriter();

        Assert::same(
            (new StatusService())->run($project->context(), $out, true),
            0,
            "Exit code must be '0': JSON output alone does not fail.",
        );
        Assert::same(
            json_decode($out->stdoutBuffer, true, flags: JSON_THROW_ON_ERROR),
            [
                'files' => [
                    'a.txt' => ['provider' => 'p/a', 'mode' => 'replace', 'status' => 'synced'],
                    'b.txt' => ['provider' => 'p/a', 'mode' => 'replace', 'status' => 'modified'],
                ],
                'providers' => ['p/a' => ['lock' => '0.2.0', 'installed' => '0.3.0']],
                'summary' => [
                    'total' => 2,
                    'synced' => 1,
                    'pending' => 0,
                    'outdated' => 0,
                    'modified' => 1,
                    'conflict' => 0,
                    'missing' => 0,
                    'orphan' => 0,
                    'ejected' => 0,
                    'error' => 0,
                ],
            ],
            'Stdout must hold only the JSON document.',
        );
        Assert::same(
            $out->stderrBuffer,
            '',
            'Drift is part of the JSON document, not a stderr line.',
        );
    }

    public function jsonReportOfEmptyLockUsesObjects(): void
    {
        $out = new BufferedOutputWriter();

        (new StatusService())
            ->run($this->project()->context(), $out, true);

        Assert::same(
            $out->stdoutBuffer,
            "{\n    \"files\": {},\n    \"providers\": {},\n    \"summary\": {\n        \"total\": 0,\n"
            . "        \"synced\": 0,\n        \"pending\": 0,\n        \"outdated\": 0,\n        \"modified\": 0,\n"
            . "        \"conflict\": 0,\n        \"missing\": 0,\n        \"orphan\": 0,\n        \"ejected\": 0,\n"
            . "        \"error\": 0\n    }\n}" . PHP_EOL,
            "Empty 'files' and 'providers' must encode as objects.",
        );
    }
    public function notAllowedProviderIsReported(): void
    {
        $project = $this->project()
            ->provider('p/denied', ['d.txt' => "d\n"])
            ->file('d.txt', "d\n")
            ->track('d.txt', 'p/denied');

        $out = new BufferedOutputWriter();

        (new StatusService())
            ->run($project->context(), $out);

        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::COMMAND_PROVIDER_NOT_ALLOWED->getMessage('d.txt', 'p/denied') . PHP_EOL,
            'Allowlist rejection must be the only stderr line.',
        );
    }

    public function noticeIsPrintedOnceForEntriesWithoutStubHash(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n", 'b.txt' => "b\n"])
            ->file('a.txt', "a\n")
            ->file('b.txt', "b\n")
            ->track('a.txt', 'p/a', extra: ['stub-hash' => null])
            ->track('b.txt', 'p/a', extra: ['stub-hash' => null]);

        $out = new BufferedOutputWriter();

        (new StatusService())
            ->run($project->context(), $out);

        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::STATUS_STUB_HASH_MISSING->getMessage(
                2,
                ConsoleMessage::FRAGMENT_ENTRIES_LACK->getMessage(),
            ) . PHP_EOL,
            'One notice must cover every legacy entry.',
        );
        Assert::same(
            $this->states($project),
            ['a.txt' => 'synced', 'b.txt' => 'synced'],
            "Legacy entries identical to their stubs must be 'synced'.",
        );
    }

    public function pendingFilesAreListedWithoutLock(): void
    {
        $project = $this->project()->provider('p/a', ['a.txt' => "a\n"]);

        $out = new BufferedOutputWriter();

        Assert::same(
            (new StatusService())->run($project->context(), $out),
            0,
            "Exit code must be '0': pending alone is not an error.",
        );
        Assert::same(
            $out->stdoutBuffer,
            'File   Provider  Mode     Status' . PHP_EOL
            . str_repeat('-', 33) . PHP_EOL
            . 'a.txt  p/a       replace  pending' . PHP_EOL
            . self::summary(1, ['pending' => 1]) . PHP_EOL,
            'Pending row must be listed.',
        );
        FileAssert::fileDoesNotExist(
            "{$this->tempDir}/scaffold-lock.json",
            'Status must not create the lock.',
        );
    }

    /**
     * @param Closure(InspectionProject): void $arrange Arranges the project.
     * @param array<string, string> $expected Expected states keyed by destination.
     */
    #[DataProvider(StatusServiceProvider::class . '::states')]
    public function reportsTheStateOfEveryFile(Closure $arrange, array $expected, int $exitCode): void
    {
        $project = $this->project();

        $arrange($project);

        Assert::same(
            $this->states($project),
            $expected,
            'States of the files must match.',
        );
        Assert::same(
            (new StatusService())->run($project->context(), new BufferedOutputWriter(), exitCode: true),
            $exitCode,
            "Exit code with '--exit-code' must follow the states.",
        );
    }

    public function runPadsTheStatusColumnToItsMinimumWidth(): void
    {
        $this->project()->track('../evil', 'p/a');

        $out = new BufferedOutputWriter();

        (new StatusService())
            ->run(ProjectContext::create($this->tempDir), $out);

        Assert::same(
            $out->stdoutBuffer,
            'File     Provider  Mode     Status' . PHP_EOL
            . str_repeat('-', 34) . PHP_EOL
            . '../evil  p/a       replace  error' . PHP_EOL
            . self::summary(1, ['error' => 1]) . PHP_EOL,
            'Status column must be at least six characters wide.',
        );
    }

    public function runPrintsEmptyMessageWhenNoFilesTracked(): void
    {
        $this->project();

        $out = new BufferedOutputWriter();

        Assert::same(
            (new StatusService())->run(ProjectContext::create($this->tempDir), $out, false, true),
            0,
            "Exit code must be '0': an empty lock is clean.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::STATUS_NO_FILES->getMessage() . PHP_EOL,
            'Empty message must be the only output.',
        );
    }

    public function runPrintsTableAndSummaryLine(): void
    {
        $this->project()
            ->provider('p/a', ['a' => "a\n", 'b' => "b\n", 'c' => "c\n"])
            ->file('a', "a\n")
            ->file('b', "b\n")
            ->file('c', "c\n")
            ->track('a', 'p/a')
            ->track('b', 'p/a')
            ->track('c', 'p/a')
            ->file('b', "mine\n")
            ->stub('p/a', 'c', "c2\n");

        $out = new BufferedOutputWriter();

        Assert::same(
            (new StatusService())->run(ProjectContext::create($this->tempDir), $out),
            0,
            "Exit code must be '0': drift does not fail without '--exit-code'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            'File  Provider  Mode     Status' . PHP_EOL
            . str_repeat('-', 33) . PHP_EOL
            . 'a     p/a       replace  synced' . PHP_EOL
            . 'b     p/a       replace  modified' . PHP_EOL
            . 'c     p/a       replace  outdated' . PHP_EOL
            . self::summary(3, ['synced' => 1, 'outdated' => 1, 'modified' => 1]) . PHP_EOL,
            'Table rows and summary must match.',
        );
    }

    public function runReturnsErrorWhenAnyEntryIsInErrorState(): void
    {
        $this
            ->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->track('../evil', 'p/a');

        Assert::same(
            (new StatusService())->run(ProjectContext::create($this->tempDir), new BufferedOutputWriter()),
            1,
            "Exit code must be '1' for an 'error' state.",
        );
    }

    public function runSizesTheStatusColumnByItsLongestValue(): void
    {
        $this
            ->project()
            ->provider('p/a', ['a' => "a\n"])
            ->file('a', "a\n")
            ->track('a', 'p/a');

        $out = new BufferedOutputWriter();

        (new StatusService())
            ->run(ProjectContext::create($this->tempDir), $out);

        Assert::same(
            $out->stdoutBuffer,
            'File  Provider  Mode     Status' . PHP_EOL
            . str_repeat('-', 31) . PHP_EOL
            . 'a     p/a       replace  synced' . PHP_EOL
            . self::summary(1, ['synced' => 1]) . PHP_EOL,
            'Separator must cover a six-character status column.',
        );
    }

    public function stubSymlinkEscapingTheProviderIsError(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->file('outside.txt', "a\n");

        unlink("{$this->tempDir}/vendor/p/a/stubs/a.txt");

        if (@symlink("{$this->tempDir}/outside.txt", "{$this->tempDir}/vendor/p/a/stubs/a.txt") === false) {
            throw new SkipTest('Symbolic links are not available.');
        }

        Assert::same(
            $this->states($project),
            ['a.txt' => 'error'],
            "Escaping stub must be in the 'error' state.",
        );
    }

    public function throwRuntimeExceptionWhenJsonReportCannotBeEncoded(): void
    {
        $project = $this
            ->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->installed(['p/a' => "\xB1"]);

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::SERVICE_STATUS_ENCODE_FAILED->getMessage(
                    'Malformed UTF-8 characters, possibly incorrectly encoded',
                ),
            )
            ->withCode(0);

        (new StatusService())->run($project->context(), new BufferedOutputWriter(), true);
    }

    public function throwRuntimeExceptionWhenProjectManifestIsMissing(): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::CONFIG_MANIFEST_NOT_FOUND->getMessage("{$this->tempDir}/vendor/composer.json"),
            );

        (new StatusService())->run(ProjectContext::create("{$this->tempDir}/vendor"), new BufferedOutputWriter());
    }

    public function unloadableProviderManifestIsReported(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->file('vendor/p/a/composer.json', '{"extra": {"scaffold": {"copy": "nope"}}}');

        $out = new BufferedOutputWriter();

        (new StatusService())
            ->run($project->context(), $out);

        Assert::same(
            $out->stderrBuffer,
            Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage('p/a', Message::MANIFEST_COPY_MISSING->getMessage())
            . PHP_EOL,
            'Manifest failure must be the only stderr line.',
        );
    }

    /**
     * Returns the builder of the temporary project, with `p/a` and `other/pkg` allowed.
     */
    private function project(): InspectionProject
    {
        return (new InspectionProject($this->tempDir))->composer(['p/a', 'other/pkg']);
    }

    /**
     * Returns the state of every file of the JSON status report, keyed by destination.
     *
     * @return array<string, string> States keyed by destination.
     */
    private function states(InspectionProject $project): array
    {
        $out = new BufferedOutputWriter();

        (new StatusService())->run($project->context(), $out, true);

        /** @var array{files: array<string, array{status: string}>} $report */
        $report = json_decode($out->stdoutBuffer, true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn(array $info): string => $info['status'], $report['files']);
    }

    /**
     * Returns the status summary line for `$total` files and the non-zero counts of `$counts`.
     *
     * @param int $total Number of files in the table.
     * @param array<string, int> $counts Non-zero counts keyed by state, in summary order.
     *
     * @return string Summary line without the trailing newline.
     */
    private static function summary(int $total, array $counts): string
    {
        return ConsoleMessage::STATUS_SUMMARY->getMessage(
            $total,
            $total === 1 ? ConsoleMessage::FRAGMENT_FILE->getMessage() : ConsoleMessage::FRAGMENT_FILES->getMessage(),
            implode(
                ', ',
                array_map(
                    static fn(string $state, int $count): string => ConsoleMessage::FRAGMENT_COUNT->getMessage(
                        $count,
                        $state,
                    ),
                    array_keys($counts),
                    $counts,
                ),
            ),
        );
    }
}
