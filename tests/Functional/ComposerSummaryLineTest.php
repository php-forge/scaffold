<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Functional;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\EventSubscriber;
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Tests\Support\{ComposerEventHarness, ExpectedSummary, FileAssert, TempDirectoryTrait};
use Testo\Assert;
use Testo\Test;

use function array_keys;
use function file_get_contents;
use function file_put_contents;
use function str_replace;
use function unlink;

/**
 * Functional tests for the one-line summary that {@see EventSubscriber} prints at the end of a Composer run.
 */
#[Test]
final class ComposerSummaryLineTest
{
    use ComposerEventHarness;
    use TempDirectoryTrait;

    public function summaryIsPrintedOnlyWhenSomethingWasWrittenOrWarned(): void
    {
        $builder = $this->project(['allowed-packages' => ['demo/scaffold', 'demo/missing']]);

        $manifest = ['copy' => ['a.txt', 'b.txt']];

        $lockPath = "{$builder->getProjectRoot()}/scaffold-lock.json";

        $builder->createStubFile('demo/scaffold', 'a.txt', "a\n");
        $builder->createStubFile('demo/scaffold', 'b.txt', "b\n");

        Assert::same(
            $this->dispatch($builder, $manifest),
            Message::SCAFFOLD_PROVIDER_NOT_INSTALLED->getMessage('demo/missing') . PHP_EOL
            . ExpectedSummary::line(2, 0, ExpectedSummary::part(1, Message::FRAGMENT_WARNING)) . PHP_EOL,
            'First run must warn about the missing provider and end with the summary.',
        );
        Assert::same(
            [
                (new LockFile($builder->getProjectRoot()))->read()['providers'],
                array_keys((new LockFile($builder->getProjectRoot()))->read()['files']),
            ],
            [
                [
                    'demo/scaffold' => [
                        'version' => '1.0.0',
                        'path' => str_replace('\\', '/', "{$builder->getVendorDir()}/demo/scaffold"),
                    ],
                ],
                ['a.txt', 'b.txt'],
            ],
            'Lock must record the provider and both files.',
        );

        $this->project();

        $lock = (string) file_get_contents($lockPath);

        Assert::same(
            $this->dispatch($builder, $manifest),
            '',
            'A no-op run must be silent.',
        );
        FileAssert::contentSame(
            $lockPath,
            $lock,
            'A no-op run must leave the lock byte-identical.',
        );

        unlink("{$builder->getProjectRoot()}/a.txt");

        Assert::same(
            $this->dispatch($builder, $manifest),
            ExpectedSummary::line(1, 1) . PHP_EOL,
            'A restored file must count as written.',
        );

        file_put_contents(
            "{$builder->getProjectRoot()}/b.txt",
            "mine\n",
        );

        Assert::same(
            $this->dispatch($builder, $manifest),
            Message::SCAFFOLD_PREFIXED->getMessage(Message::SCAFFOLD_USER_MODIFIED_SKIPPED->getMessage('b.txt'))
            . PHP_EOL
            . ExpectedSummary::line(0, 1, ExpectedSummary::part(1, Message::FRAGMENT_WARNING)) . PHP_EOL,
            'A warned run must end with the summary.',
        );
    }
}
