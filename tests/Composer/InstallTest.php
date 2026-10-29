<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Composer;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Tests\Support\{ExpectedSummary, FileAssert};
use Testo\Assert;
use Testo\Test;

use function file_get_contents;

/**
 * Functional tests that run `composer install` with the real Composer binary and the plugin installed from this
 * working tree.
 */
#[Test]
final class InstallTest extends AbstractComposerScenario
{
    /**
     * Ensures a custom `vendor-dir` is honored by the Composer run and by the installed `vendor/bin/scaffold`.
     */
    public function customVendorDirIsResolvedByComposerAndTheCli(): void
    {
        $this->writeComposerJson(config: ['vendor-dir' => 'lib/vendor']);

        Assert::same(
            $this->composer(['install'])->status(),
            '0',
            "Install must exit with '0'.",
        );
        FileAssert::contentSame(
            $this->path('replace.txt'),
            "replace v1\n",
            'Files must be written.',
        );
        Assert::same(
            $this->lock()['providers']['fixture/provider'] ?? null,
            ['version' => '1.0.0', 'path' => 'lib/vendor/fixture/provider'],
            "Provider path must follow the vendor dir 'lib/vendor'.",
        );

        $status = $this->scaffold(['status', '--exit-code'], 'lib/vendor/bin');

        Assert::same(
            $status->status(),
            '0',
            "Status must exit with '0'.",
        );

        self::assertOutputContains(
            $status->stdout,
            Message::STATUS_SUMMARY->getMessage(
                6,
                Message::FRAGMENT_FILES->getMessage(),
                Message::FRAGMENT_COUNT->getMessage(6, 'synced'),
            ),
            "CLI must report every file as 'synced'.",
        );
    }

    /**
     * Ensures the first install applies every mode and records the provider, and a second install is a silent no-op.
     */
    public function firstInstallScaffoldsEveryModeAndSecondInstallIsNoop(): void
    {
        $this->writeComposerJson();

        $first = $this->composer(['install']);

        Assert::same(
            $first->status(),
            '0',
            "First install must exit with '0'.",
        );

        self::assertOutputContains(
            $first->stdout,
            ExpectedSummary::line(6, 0),
            'Summary must count six written files.',
        );

        Assert::same(
            [
                'replace.txt' => file_get_contents($this->path('replace.txt')),
                'config/preserve.txt' => file_get_contents($this->path('config/preserve.txt')),
                '.gitignore' => file_get_contents($this->path('.gitignore')),
                'prepend.txt' => file_get_contents($this->path('prepend.txt')),
            ],
            [
                'replace.txt' => "replace v1\n",
                'config/preserve.txt' => "preserve v1\n",
                '.gitignore' => "# Dependencies\n/vendor\n",
                'prepend.txt' => "HEADER\n",
            ],
            'Each mode must write its file.',
        );
        Assert::same(
            $this->lock()['providers']['fixture/provider'] ?? null,
            ['version' => '1.0.0', 'path' => 'vendor/fixture/provider'],
            "Provider must be recorded at 'vendor/fixture/provider'.",
        );

        $lock = file_get_contents($this->path('scaffold-lock.json'));

        $second = $this->composer(['install']);

        Assert::same(
            $second->status(),
            '0',
            "Second install must exit with '0'.",
        );

        self::assertOutputLacks(
            $second->stdout . $second->stderr,
            Message::SCAFFOLD_PREFIXED->getMessage(''),
            "A no-op run must write no '[scaffold]' line.",
        );

        FileAssert::contentSame(
            $this->path('scaffold-lock.json'),
            (string) $lock,
            'Lock must be byte-identical.',
        );
    }

    /**
     * Ensures `--no-plugins` writes nothing and the next plain install catches up.
     */
    public function noPluginsSkipsScaffoldingUntilThePlainInstall(): void
    {
        $this->writeComposerJson();

        Assert::same(
            $this->composer(['install', '--no-plugins'])->status(),
            '0',
            "Install must exit with '0'.",
        );
        FileAssert::fileDoesNotExist(
            $this->path('replace.txt'),
            'Plugins disabled: nothing may be written.',
        );
        FileAssert::fileDoesNotExist(
            $this->path('scaffold-lock.json'),
            'Plugins disabled: no lock may be written.',
        );
        Assert::same(
            $this->composer(['install'])->status(),
            '0',
            "Plain install must exit with '0'.",
        );
        FileAssert::contentSame(
            $this->path('replace.txt'),
            "replace v1\n",
            'Plain install must write the files.',
        );
        FileAssert::fileExists(
            $this->path('scaffold-lock.json'),
            'Plain install must write the lock.',
        );
    }
}
