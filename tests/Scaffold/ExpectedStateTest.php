<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold;

use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Scaffold\ExpectedState;
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_slice;
use function range;

/**
 * Unit tests for {@see ExpectedState} merging the mappings of the allowed and installed providers.
 */
#[Test]
#[Covers(ExpectedState::class)]
final class ExpectedStateTest
{
    use TempDirectoryTrait;

    public function failedManifestIsRecordedWithWarning(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('vendor/p/a/composer.json', '{broken');

        $state = ExpectedState::build($project->context(), []);

        Assert::same(
            [$state->hasFailed('p/a'), $state->mapping('a.txt'), $state->warnings],
            [true, null, [Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage('p/a', 'Syntax error')]],
            'Failed provider must be marked, contribute no mapping and be reported.',
        );
    }

    public function lastAllowedProviderWinsPerDestination(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a', 'p/b'])
            ->provider('p/a', ['shared.txt' => "a\n", 'a.txt' => "a\n"])
            ->provider('p/b', ['shared.txt' => "b\n"]);

        $state = ExpectedState::build($project->context(), []);

        Assert::same(
            [
                $state->mapping('shared.txt')?->providerName,
                $state->mapping('a.txt')?->providerName,
                $state->hasFailed('p/a'),
                $state->warnings,
            ],
            ['p/b', 'p/a', false, []],
            'Later provider must win the shared destination without failures or warnings.',
        );
    }

    public function notAllowedAndNotInstalledProvidersAreIgnored(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a', 'p/missing'])
            ->provider('p/a', ['a.txt' => "a\n"])
            ->provider('p/denied', ['d.txt' => "d\n"]);

        $state = ExpectedState::build($project->context(), []);

        Assert::same(
            [$state->mapping('d.txt'), $state->hasFailed('p/missing'), $state->mapping('a.txt')?->providerName],
            [null, false, 'p/a'],
            'Only the allowed and installed provider must contribute.',
        );
    }

    public function orphanReportListsTenOrphansAndCountsTheOthers(): void
    {
        $names = array_map(
            static fn(int $index): string => "f{$index}.txt",
            range(0, 11),
        );

        $lines = (new ExpectedState([]))
            ->orphanReport(
                array_fill_keys(
                    $names,
                    [
                        'hash' => 'sha256:x',
                        'provider' => 'p/a',
                        'source' => 's',
                        'mode' => 'replace',
                    ],
                ),
            );

        Assert::same(
            $lines,
            [
                ConsoleMessage::SCAFFOLD_ORPHAN_HEADER->getMessage(12, ConsoleMessage::FRAGMENT_FILES->getMessage()),
                ...array_map(
                    static fn(string $name): string => ConsoleMessage::SCAFFOLD_ORPHAN_ENTRY->getMessage($name, 'p/a'),
                    array_slice($names, 0, 10),
                ),
                ConsoleMessage::SCAFFOLD_ORPHAN_MORE->getMessage(2),
            ],
            "Header, ten files and the remainder line of '2' expected.",
        );
    }

    public function orphanReportListsTenOrphansWithoutRemainderLine(): void
    {
        $names = array_map(
            static fn(int $index): string => "f{$index}.txt",
            range(0, 9),
        );

        $lines = (new ExpectedState([]))
            ->orphanReport(
                array_fill_keys(
                    $names,
                    [
                        'hash' => 'sha256:x',
                        'provider' => 'p/a',
                        'source' => 's',
                        'mode' => 'replace',
                    ],
                ),
            );

        Assert::same(
            $lines,
            [
                ConsoleMessage::SCAFFOLD_ORPHAN_HEADER->getMessage(10, ConsoleMessage::FRAGMENT_FILES->getMessage()),
                ...array_map(
                    static fn(string $name): string => ConsoleMessage::SCAFFOLD_ORPHAN_ENTRY->getMessage($name, 'p/a'),
                    $names,
                ),
            ],
            'Header and ten files expected, without a remainder line.',
        );
    }

    /**
     * Ensures ejected, shipped and failed-provider entries are not orphans, even when the failed provider comes first.
     */
    public function orphansExcludeEjectedShippedAndFailedProviderEntries(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/broken', 'p/a'])
            ->provider('p/a', ['a.txt' => "a\n"])
            ->provider('p/broken', ['b.txt' => "b\n"])
            ->file('vendor/p/broken/composer.json', '{broken');

        $state = ExpectedState::build($project->context(), []);

        $entry = [
            'hash' => 'sha256:x',
            'source' => 's',
            'mode' => 'replace',
        ];

        Assert::same(
            [
                array_keys(
                    $state->orphans(
                        [
                            'a.txt' => [...$entry, 'provider' => 'p/a'],
                            'gone.txt' => [...$entry, 'provider' => 'p/a'],
                            123 => [...$entry, 'provider' => 'p/a'],
                            'ejected.txt' => [...$entry, 'provider' => 'p/a', 'ejected' => true],
                            'b.txt' => [...$entry, 'provider' => 'p/broken'],
                            'old.txt' => [...$entry, 'provider' => 'p/removed'],
                        ],
                    ),
                ),
                $state->failedProviders(),
                array_keys($state->all()),
            ],
            [['gone.txt', 123, 'old.txt'], ['p/broken'], ['a.txt']],
            'Only unshipped, non-ejected entries of loadable providers must be orphans.',
        );
    }

    /**
     * Ensures a provider root outside vendor is reported and the default root still loads the manifest.
     */
    public function providerRootWarningIsCollected(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "a\n"]);

        $state = ExpectedState::build($project->context(), ['p/a' => ['path' => '/elsewhere/p/a']]);

        Assert::same(
            [$state->warnings, $state->mapping('a.txt')?->providerName],
            [[ConsoleMessage::SCAFFOLD_PROVIDER_ROOT_OUTSIDE_VENDOR->getMessage('p/a')], 'p/a'],
            'Root warning must be collected and the default root must still load the manifest.',
        );
    }
}
