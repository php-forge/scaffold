<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Composer;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Tests\Support\{ExpectedSummary, FileAssert};
use Testo\Assert;
use Testo\Test;

use function file_get_contents;
use function file_put_contents;

/**
 * Functional tests for `auto: false`, provider bumps, orphans, `prune` and `sync` with the real Composer binary.
 */
#[Test]
final class LifecycleTest extends AbstractComposerScenario
{
    /**
     * Ensures an `auto: false` project is left untouched by Composer and bootstrapped by the `sync` command.
     */
    public function autoDisabledProjectIsBootstrappedBySync(): void
    {
        $this->writeComposerJson(['auto' => false]);

        $install = $this->composer(['install']);

        Assert::same(
            $install->status(),
            '0',
            "Install must exit with '0'.",
        );

        self::assertOutputContains(
            $install->stdout,
            Message::SCAFFOLD_AUTO_DISABLED->getMessage(),
            'Hook must announce the opt-out.',
        );

        FileAssert::fileDoesNotExist(
            $this->path('scaffold-lock.json'),
            'Composer may write nothing.',
        );
        FileAssert::directoryDoesNotExist(
            $this->path('vendor/composer/composer'),
            "The consumer must not install 'composer/composer'.",
        );

        $pending = $this->scaffold(['status', '--exit-code']);

        Assert::same(
            $pending->status(1),
            '1',
            "Status must exit with '1'.",
        );

        self::assertOutputContains(
            $pending->stdout,
            Message::STATUS_SUMMARY->getMessage(
                6,
                Message::FRAGMENT_FILES->getMessage(),
                Message::FRAGMENT_COUNT->getMessage(6, 'pending'),
            ),
            "Every file must be 'pending'.",
        );

        $sync = $this->scaffold(['sync']);

        Assert::same(
            $sync->status(),
            '0',
            "Sync must exit with '0'.",
        );

        self::assertOutputContains(
            $sync->stdout,
            ExpectedSummary::withCommand('sync', 6, 0),
            'Summary must count six written files.',
        );

        FileAssert::contentSame(
            $this->path('replace.txt'),
            "replace v1\n",
            'Sync must write the files.',
        );
        Assert::same(
            $this->scaffold(['status', '--exit-code'])->status(),
            '0',
            "Status must exit with '0'.",
        );
        Assert::same(
            $this->scaffold(['sync', '--dry-run', '--exit-code'])->status(),
            '0',
            "Sync preview must exit with '0'.",
        );
    }

    /**
     * Ensures a provider update applies each mode and reports the orphan, which `prune` then removes.
     */
    public function providerBumpUpdatesEachModeAndPruneRemovesTheOrphan(): void
    {
        $this->writeComposerJson();

        Assert::same(
            $this->composer(['install'])->status(),
            '0',
            "First install must exit with '0'.",
        );

        file_put_contents($this->path('edited.txt'), "mine\n");

        $this->writeComposerJson(providerVersion: '2.0.0');

        $update = $this->composer(['update', 'fixture/provider']);

        Assert::same(
            $update->status(),
            '0',
            "Update must exit with '0'.",
        );
        Assert::same(
            [
                'replace.txt' => file_get_contents($this->path('replace.txt')),
                'edited.txt' => file_get_contents($this->path('edited.txt')),
                'config/preserve.txt' => file_get_contents($this->path('config/preserve.txt')),
                '.gitignore' => file_get_contents($this->path('.gitignore')),
                'prepend.txt' => file_get_contents($this->path('prepend.txt')),
                'added.txt' => file_get_contents($this->path('added.txt')),
                'docs/old/dropped.txt' => file_get_contents($this->path('docs/old/dropped.txt')),
            ],
            [
                'replace.txt' => "replace v2\n",
                'edited.txt' => "mine\n",
                'config/preserve.txt' => "preserve v1\n",
                '.gitignore' => "# Dependencies\n/vendor\n/node_modules\n",
                'prepend.txt' => "HEADER\n",
                'added.txt' => "added v2\n",
                'docs/old/dropped.txt' => "dropped v1\n",
            ],
            'Each mode must apply the bump; the user edit and the orphan must stay.',
        );

        self::assertOutputContains(
            $update->stderr,
            Message::SCAFFOLD_CONFLICTS->getMessage(1, Message::FRAGMENT_CONFLICT_FILE->getMessage()),
            'Conflict hint must be printed.',
        );
        self::assertOutputContains(
            $update->stderr,
            Message::SCAFFOLD_ORPHAN_HEADER->getMessage(1, Message::FRAGMENT_FILE->getMessage()),
            'Orphan warning must be printed.',
        );
        self::assertOutputContains(
            $update->stderr,
            Message::SCAFFOLD_ORPHAN_ENTRY->getMessage('docs/old/dropped.txt', 'fixture/provider'),
            "Orphan 'docs/old/dropped.txt' must be named.",
        );
        self::assertOutputContains(
            $update->stdout,
            ExpectedSummary::line(
                3,
                2,
                ExpectedSummary::part(1, Message::FRAGMENT_OUTDATED),
                ExpectedSummary::part(1, Message::FRAGMENT_CONFLICT),
                ExpectedSummary::part(1, Message::FRAGMENT_ORPHAN),
                ExpectedSummary::part(1, Message::FRAGMENT_WARNING),
            ),
            'Summary must count every state.',
        );

        $preview = $this->scaffold(['prune', '--exit-code']);

        Assert::same(
            $preview->status(1),
            '1',
            "Prune preview must exit with '1'.",
        );

        self::assertOutputContains(
            $preview->stdout,
            'docs/old/dropped.txt  fixture/provider  unmodified  delete',
            'Preview must list the orphan.',
        );

        $prune = $this->scaffold(['prune', '--force']);

        Assert::same(
            $prune->status(),
            '0',
            "Prune must exit with '0'.",
        );

        self::assertOutputContains(
            $prune->stdout,
            Message::PRUNE_SUMMARY->getMessage(1, 0, 0, Message::FRAGMENT_ERRORS->getMessage()),
            'Summary must count the removal.',
        );

        FileAssert::directoryDoesNotExist(
            $this->path('docs'),
            'Empty parent directories must be removed.',
        );
        Assert::false(
            isset($this->lock()['files']['docs/old/dropped.txt']),
            "Entry 'docs/old/dropped.txt' must leave the lock.",
        );
    }
}
