<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Services;

use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Scaffold\Lock\{Hasher, LockFile};
use PHPForge\Scaffold\Scaffold\PathResolver;
use PHPForge\Scaffold\Services\{ReapplyService, ReapplySummary};
use PHPForge\Scaffold\Tests\Provider\ReapplyServiceProvider;
use PHPForge\Scaffold\Tests\Support\{
    BufferedOutputWriter,
    ExpectedSummary,
    FileAssert,
    InspectionProject,
    TempDirectoryTrait,
};
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\{DataProvider, DataSet};
use Testo\Expect;
use Testo\Test;

use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function hash;
use function mkdir;
use function preg_quote;
use function str_replace;
use function umask;

/**
 * Unit tests for {@see ReapplyService} covering the write path: modes, filters, force, and the error paths.
 *
 * {@see ReapplyServiceProvider} for test case data providers.
 */
#[Test]
#[Covers(ReapplyService::class)]
#[Covers(ReapplySummary::class)]
final class ReapplyServiceTest
{
    use TempDirectoryTrait;

    public function adoptedEntryIsTreatedAsUserModifiedUntilForced(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['ecs.php' => "stub\n"])
            ->file('ecs.php', "mine\n")
            ->track('ecs.php', 'p/a', extra: ['adopted' => true]);

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, 'ecs.php'),
            0,
            "Exit code of a user-modified skip must be '0'.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_USER_MODIFIED->getMessage('ecs.php') . PHP_EOL,
            'Adopted file must be reported as user-modified.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::reapply(0, 0, 1, 0, 'user-modified') . PHP_EOL,
            'Summary must count the skip.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/ecs.php",
            "mine\n",
            'User content must be kept.',
        );

        $this->reapply($project, new BufferedOutputWriter(), 'ecs.php', force: true);

        FileAssert::contentSame(
            "{$this->tempDir}/ecs.php",
            "stub\n",
            'Force must rewrite from the stub.',
        );
        Assert::same(
            $project->lock()->read()['files']['ecs.php'] ?? null,
            self::writtenEntry('ecs.php', "stub\n"),
            "Force must record the stub hashes and clear 'adopted'.",
        );
        Assert::same(
            $project->lock()->read()['providers'],
            ['p/a' => ['version' => '1.0.0', 'path' => 'vendor/p/a']],
            'Rewritten lock must keep the providers.',
        );
    }

    public function appendMergesTheMissingProviderLineAndRecordsTheStubHash(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['.env.dist' => "A=1\nB=2\n"], ['.env.dist' => 'append'])
            ->file('.env.dist', "A=1\nC=3\n")
            ->track('.env.dist', 'p/a', 'append');

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, '.env.dist'),
            0,
            "Exit code of a merge must be '0'.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/.env.dist",
            "A=1\nC=3\n\nB=2\n",
            'Missing line must be merged and the consumer line kept.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('.env.dist') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 0) . PHP_EOL,
            'Write and summary must be reported.',
        );

        $entry = $project->lock()->read()['files']['.env.dist'] ?? [];

        Assert::same(
            [$entry['hash'] ?? null, $entry['stub-hash'] ?? null],
            [Hasher::hash("{$this->tempDir}/.env.dist"), Hasher::hash("{$this->tempDir}/vendor/p/a/stubs/.env.dist")],
            'Hash and stub hash must be refreshed.',
        );

        $second = new BufferedOutputWriter();

        $this->reapply($project, $second, '.env.dist');

        Assert::same(
            $second->stdoutBuffer,
            ConsoleMessage::REAPPLY_ALREADY_UP_TO_DATE->getMessage('.env.dist') . PHP_EOL
            . ExpectedSummary::reapply(0, 1, 0, 0) . PHP_EOL,
            'Second run must be a no-op.',
        );
    }

    public function appendUpToDateContinuesToTheNextEntry(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['.env.dist' => "A=1\n", 'config/params.php' => "stub\n"], ['.env.dist' => 'append'])
            ->file('.env.dist', "A=1\n")
            ->file('config/params.php', "stub\n")
            ->track('.env.dist', 'p/a', 'append')
            ->track('config/params.php', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out),
            0,
            "Exit code must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_ALREADY_UP_TO_DATE->getMessage('.env.dist') . PHP_EOL
            . ConsoleMessage::REAPPLY_APPLIED->getMessage('config/params.php') . PHP_EOL
            . ExpectedSummary::reapply(1, 1, 0, 0) . PHP_EOL,
            'Iteration must continue to the replace entry.',
        );
    }

    public function appendUsesTheMergeStrategyRecordedInTheLock(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['.gitignore' => "# b\nx\n"], ['.gitignore' => 'append'])
            ->file('.gitignore', "# a\nx\n")
            ->track('.gitignore', 'p/a', 'append', ['merge' => 'text']);

        $this->reapply($project, new BufferedOutputWriter(), '.gitignore');

        FileAssert::contentSame(
            "{$this->tempDir}/.gitignore",
            "# a\nx\n\n# b\n",
            "Recorded 'text' strategy must override the 'list' default.",
        );
        Assert::same(
            $project->lock()->read()['files']['.gitignore']['merge'] ?? null,
            'text',
            'Recorded strategy must be kept.',
        );
    }

    public function appendWithAnUnreadableDestinationIsAnError(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['.env.dist' => "A=1\n"], ['.env.dist' => 'append'])
            ->track('.env.dist', 'p/a', 'append');

        // A directory where the destination file is expected cannot be read as a file.
        mkdir("{$this->tempDir}/.env.dist");

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, '.env.dist'),
            1,
            "Exit code must be '1' when the destination cannot be read.",
        );

        // The PHP notice depends on the platform and the filesystem, so the reason is matched as a pattern.
        $expected = ConsoleMessage::REAPPLY_FAILED->getMessage(
            '.env.dist',
            Message::FILESYSTEM_FAILED->getMessage(
                Message::MODE_DESTINATION_UNREADABLE->getMessage(PathResolver::destination($this->tempDir, '.env.dist')),
                'file_get_contents(%REASON%',
            ),
        ) . PHP_EOL;

        Assert::string($out->stderrBuffer)->matchesRegex(
            '/\A' . str_replace('%REASON%', '.+', preg_quote($expected, '/')) . '\z/',
            'Mode failure must be the only stderr line, with its reason.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::reapply(0, 0, 0, 1) . PHP_EOL,
            'Summary must count the error.',
        );
    }

    public function ejectedEntryIsSkippedWithoutForce(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['config/params.php' => "stub\n"])
            ->file('config/params.php', "stub\n")
            ->track('config/params.php', 'p/a', extra: ['ejected' => true]);

        $before = file_get_contents(
            "{$this->tempDir}/scaffold-lock.json",
        );

        $out = new BufferedOutputWriter();

        $this->reapply($project, $out);

        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_EJECTED->getMessage('config/params.php') . PHP_EOL,
            'Ejected entry must be reported.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::reapply(0, 0, 1, 0, 'ejected') . PHP_EOL,
            'Summary must count the skip.',
        );
        Assert::same(
            file_get_contents("{$this->tempDir}/scaffold-lock.json"),
            $before,
            'Lock must be untouched.',
        );
    }

    public function ensureDirectoryFailureContinuesToTheNextEntry(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['deep/config/params.php' => "stub\n", 'top.txt' => "stub\n"])
            // A regular file where the parent directory belongs blocks its creation.
            ->file('deep/config', 'blocker')
            ->file('top.txt', "stub\n")
            ->track('deep/config/params.php', 'p/a')
            ->track('top.txt', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, force: true),
            1,
            "Exit code must be '1' when a directory cannot be created.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_FAILED->getMessage(
                'deep/config/params.php',
                Message::FILESYSTEM_FAILED->getMessage(
                    Message::PATH_DIRECTORY_CREATE_FAILED->getMessage(
                        dirname(PathResolver::destination($this->tempDir, 'deep/config/params.php')),
                    ),
                    'mkdir(): File exists',
                ),
            ) . PHP_EOL,
            'Directory failure must be reported with its reason.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('top.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 1) . PHP_EOL,
            'Next entry must still be reapplied.',
        );
    }

    public function fileFilterReappliesOnlyTheMatchingEntry(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "a\n", 'b.txt' => "b\n"])
            ->file('a.txt', "a\n")
            ->file('b.txt', "b\n")
            ->track('a.txt', 'p/a')
            ->track('b.txt', 'p/a');

        $out = new BufferedOutputWriter();

        $this->reapply($project, $out, 'b.txt', force: true);

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('b.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 0) . PHP_EOL,
            "Only 'b.txt' must be reapplied.",
        );
    }

    public function forceOverwritesAUserModifiedFileAndUpdatesTheLock(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['config/params.php' => "stub\n"])
            ->file('config/params.php', "stub\n")
            ->track('config/params.php', 'p/a')
            ->file('config/params.php', "user-edited\n");

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, 'config/params.php', force: true),
            0,
            "Exit code of a forced write must be '0'.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('config/params.php') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 0) . PHP_EOL,
            'Write must be reported.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/config/params.php",
            "stub\n",
            'Stub must overwrite the user content.',
        );
        Assert::same(
            $project->lock()->read(),
            [
                'providers' => ['p/a' => ['version' => '1.0.0', 'path' => 'vendor/p/a']],
                'files' => ['config/params.php' => self::writtenEntry('config/params.php', "stub\n")],
            ],
            'Lock must hold the new hashes and keep the providers section.',
        );
    }

    public function forceReappliesAnEjectedEntryAndClearsTheFlag(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['config/params.php' => "stub\n"])
            ->file('config/params.php', "mine\n")
            ->track('config/params.php', 'p/a', extra: ['ejected' => true]);

        $this->reapply($project, new BufferedOutputWriter(), 'config/params.php', force: true);

        FileAssert::contentSame(
            "{$this->tempDir}/config/params.php",
            "stub\n",
            'Stub must be written.',
        );
        Assert::same(
            $project->lock()->read()['files']['config/params.php'] ?? null,
            self::writtenEntry('config/params.php', "stub\n"),
            "Force must clear 'ejected'.",
        );
    }

    /**
     * Ensures force rewrites an `append` file from its stub, discarding the consumer additions.
     */
    public function forceRewritesAnAppendFileFromTheStub(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['.env.dist' => "A=1\n"], ['.env.dist' => 'append'])
            ->file('.env.dist', "A=1\nC=3\n")
            ->track('.env.dist', 'p/a', 'append');

        $out = new BufferedOutputWriter();

        $this->reapply($project, $out, '.env.dist', force: true);

        FileAssert::contentSame(
            "{$this->tempDir}/.env.dist",
            "A=1\n",
            'Force must discard additions.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('.env.dist') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 0) . PHP_EOL,
            'Rewrite must be reported.',
        );
    }

    public function hashFailureBeforeTheWriteContinuesToTheNextEntry(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['first.txt' => "stub\n", 'second.txt' => "stub\n"])
            ->file('first.txt', "stub\n")
            ->file('second.txt', "stub\n")
            ->track('first.txt', 'p/a')
            ->track('second.txt', 'p/a');

        $first = PathResolver::destination($this->tempDir, 'first.txt');

        $this->restrictFileAccess($first, 0o000);

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out),
            1,
            "Exit code must be '1' when a destination cannot be hashed.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_FAILED->getMessage(
                'first.txt',
                Message::FILESYSTEM_FAILED->getMessage(
                    Message::LOCK_HASH_FAILED->getMessage($first),
                    "hash_file({$first}): Failed to open stream: Permission denied",
                ),
            ) . PHP_EOL,
            "Hash failure of 'first.txt' must be reported with its reason.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('second.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 1) . PHP_EOL,
            "'second.txt' must still be reapplied.",
        );
    }

    public function hashFailureOfADirectoryDestinationContinuesToTheNextEntry(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['first.txt' => "stub\n", 'second.txt' => "stub\n"])
            ->file('second.txt', "stub\n")
            ->track('first.txt', 'p/a')
            ->track('second.txt', 'p/a');

        // A directory where the destination file is expected cannot be hashed before it is overwritten.
        mkdir("{$this->tempDir}/first.txt");

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, force: true),
            1,
            "Exit code must be '1' when a destination cannot be hashed.",
        );

        // The PHP notice depends on the platform and the filesystem, so the reason is matched as a pattern.
        $expected = ConsoleMessage::REAPPLY_FAILED->getMessage(
            'first.txt',
            Message::FILESYSTEM_FAILED->getMessage(
                Message::LOCK_HASH_FAILED->getMessage(PathResolver::destination($this->tempDir, 'first.txt')),
                'hash_file(%REASON%',
            ),
        ) . PHP_EOL;

        Assert::string($out->stderrBuffer)->matchesRegex(
            '/\A' . str_replace('%REASON%', '.+', preg_quote($expected, '/')) . '\z/',
            "Hash failure of 'first.txt' must be the only stderr line, with its reason.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('second.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 1) . PHP_EOL,
            "'second.txt' must still be reapplied.",
        );
    }

    public function missingStubContinuesToTheNextEntry(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['first.txt' => "stub\n", 'second.txt' => "stub\n"])
            ->file('first.txt', "stub\n")
            ->file('second.txt', "stub\n")
            ->track('first.txt', 'p/a')
            ->track('second.txt', 'p/a')
            ->delete('vendor/p/a/stubs/first.txt');

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, force: true),
            1,
            "Exit code must be '1' when a stub is missing.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_STUB_NOT_FOUND->getMessage("{$this->tempDir}/vendor/p/a/stubs/first.txt") . PHP_EOL,
            'Missing stub must be reported.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('second.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 1) . PHP_EOL,
            "'second.txt' must still be reapplied.",
        );
    }

    public function noFilterOnAnEmptyLockPrintsOnlyTheSummary(): void
    {
        $project = (new InspectionProject($this->tempDir))->composer(['p/a']);

        $project->lock()->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out),
            0,
            "Exit code of an empty lock without filters must be '0'.",
        );
        Assert::same(
            $out->stderrBuffer,
            '',
            'No no-match error may be written without filters.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::reapply(0, 0, 0, 0) . PHP_EOL,
            'Only the summary may be printed.',
        );
    }

    public function prependAddsTheMissingBlockOnlyOnce(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['src/a.php' => "// header\n"], ['src/a.php' => 'prepend'])
            ->file('src/a.php', "code\n")
            ->track('src/a.php', 'p/a', 'prepend');

        $this->reapply($project, new BufferedOutputWriter(), 'src/a.php');

        $second = new BufferedOutputWriter();

        $this->reapply($project, $second, 'src/a.php');

        FileAssert::contentSame(
            "{$this->tempDir}/src/a.php",
            "// header\ncode\n",
            'Block must appear once.',
        );
        Assert::same(
            $second->stdoutBuffer,
            ConsoleMessage::REAPPLY_ALREADY_UP_TO_DATE->getMessage('src/a.php') . PHP_EOL
            . ExpectedSummary::reapply(0, 1, 0, 0) . PHP_EOL,
            'Second run must be a no-op.',
        );
    }

    public function preserveFileIsRestoredWhenTheDestinationIsMissing(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['config/web.php' => "stub\n"], ['config/web.php' => 'preserve'])
            ->track('config/web.php', 'p/a', 'preserve');

        $out = new BufferedOutputWriter();

        $this->reapply($project, $out, 'config/web.php');

        FileAssert::contentSame(
            "{$this->tempDir}/config/web.php",
            "stub\n",
            'Missing preserve file must be restored with the exact stub content.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('config/web.php') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 0) . PHP_EOL,
            'Restore must be reported.',
        );
    }

    public function preserveSkipContinuesToTheNextEntry(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider(
                'p/a',
                ['config/web.php' => "stub\n", 'config/params.php' => "stub\n"],
                ['config/web.php' => 'preserve'],
            )
            ->file('config/web.php', "current\n")
            ->file('config/params.php', "stub\n")
            ->track('config/web.php', 'p/a', 'preserve')
            ->track('config/params.php', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out),
            0,
            "Exit code of a preserve skip must be '0'.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_PRESERVE->getMessage('config/web.php') . PHP_EOL,
            'Preserve skip must be reported.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('config/params.php') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 1, 0, 'preserve') . PHP_EOL,
            'Next entry must still be reapplied.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/config/web.php",
            "current\n",
            'Preserve file must stay untouched without force.',
        );
    }

    public function providerFilterReappliesOnlyTheMatchingEntries(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a', 'p/b'])
            ->provider('p/a', ['a.txt' => "a\n"])
            ->provider('p/b', ['b.txt' => "b2\n"])
            ->file('a.txt', "a\n")
            ->file('b.txt', "b1\n")
            ->track('a.txt', 'p/a')
            ->track('b.txt', 'p/b');

        $out = new BufferedOutputWriter();

        $this->reapply($project, $out, provider: 'p/b');

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('b.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 0) . PHP_EOL,
            "Only the entry of 'p/b' must be reapplied.",
        );
        FileAssert::contentSame(
            "{$this->tempDir}/b.txt",
            "b2\n",
            "'b.txt' must be rewritten from its stub.",
        );
    }

    public function providerPathOutsideTheVendorDirectoryIsWarned(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['config/params.php' => "stub\n"])
            ->file('config/params.php', "stub\n")
            ->track('config/params.php', 'p/a');

        $data = $project->lock()->read();

        $data['providers']['p/a'] = [
            'version' => '1.0.0',
            'path' => '../outside-vendor',
        ];

        $project->lock()->write($data);

        $out = new BufferedOutputWriter();

        $this->reapply($project, $out, 'config/params.php', force: true);

        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::SCAFFOLD_PROVIDER_ROOT_OUTSIDE_VENDOR->getMessage('p/a') . PHP_EOL,
            'Escaping provider path must be warned about once.',
        );
    }

    public function reapplySyncsTheExecutableBitOfTheStub(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('POSIX permissions do not apply to NTFS, where Windows always reports 0777.');
        }

        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['yii' => "#!/usr/bin/env php\n"])
            ->file('yii', "#!/usr/bin/env php\n")
            ->track('yii', 'p/a');

        chmod("{$this->tempDir}/vendor/p/a/stubs/yii", 0o755);
        chmod("{$this->tempDir}/yii", 0o644);

        $oldUmask = umask(0o022);

        try {
            $this->reapply($project, new BufferedOutputWriter(), 'yii', force: true);
        } finally {
            umask($oldUmask);
        }

        Assert::same(
            fileperms("{$this->tempDir}/yii") & 0o777,
            0o755,
            "Destination must get the stub mode '0755'.",
        );
    }

    #[DataSet(['append'], 'append')]
    #[DataSet(['prepend'], 'prepend')]
    public function satisfiedMergeFileIsUpToDate(string $mode): void
    {
        $file = ".env.{$mode}.dist";

        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', [$file => "A=1\n"], [$file => $mode])
            ->file($file, "A=1\n")
            ->track($file, 'p/a', $mode);

        $out = new BufferedOutputWriter();

        $this->reapply($project, $out, $file);

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_ALREADY_UP_TO_DATE->getMessage($file) . PHP_EOL
            . ExpectedSummary::reapply(0, 1, 0, 0) . PHP_EOL,
            'Satisfied file must be reported as up to date.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/{$file}",
            "A=1\n",
            'File must be untouched.',
        );
    }

    public function stubReadFailureContinuesToTheNextEntry(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['first.txt' => "stub\n", 'second.txt' => "stub\n"])
            ->file('first.txt', "stub\n")
            ->file('second.txt', "stub\n")
            ->track('first.txt', 'p/a')
            ->track('second.txt', 'p/a');

        $stub = "{$this->tempDir}/vendor/p/a/stubs/first.txt";

        $this->restrictFileAccess($stub, 0o000);

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, force: true),
            1,
            "Exit code must be '1' when a stub cannot be read.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_FAILED->getMessage(
                'first.txt',
                Message::FILESYSTEM_FAILED->getMessage(
                    Message::MODE_COPY_FAILED->getMessage(
                        $stub,
                        PathResolver::destination($this->tempDir, 'first.txt'),
                    ),
                    "copy({$stub}): Failed to open stream: Permission denied",
                ),
            ) . PHP_EOL,
            'Stub read failure must be reported with its reason.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('second.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 1) . PHP_EOL,
            "'second.txt' must still be reapplied.",
        );
    }

    public function throwRuntimeExceptionWhenLockContainsGitConflictMarkers(): void
    {
        $project = (new InspectionProject($this->tempDir))->composer(['p/a']);

        file_put_contents(
            "{$this->tempDir}/scaffold-lock.json",
            "{\n<<<<<<< HEAD\n    \"files\": {}\n=======\n    \"files\": {\"a\": {}}\n>>>>>>> main\n}\n",
        );

        Expect::exception(RuntimeException::class)->withMessage(
            Message::LOCK_CONFLICT_MARKERS->getMessage((new LockFile($this->tempDir))->getPath(), 'Syntax error'),
        );

        $this->reapply($project, new BufferedOutputWriter(), force: true);
    }

    public function unknownLockModeIsAnError(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "stub\n"])
            ->file('a.txt', "stub\n")
            ->track('a.txt', 'p/a', 'copy');

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out),
            1,
            "Exit code must be '1' for an unknown mode.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_FAILED->getMessage('a.txt', Message::LOCK_MODE_UNKNOWN->getMessage('copy')) . PHP_EOL,
            'Unknown mode must be reported.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ExpectedSummary::reapply(0, 0, 0, 1) . PHP_EOL,
            'Summary must count the error.',
        );
    }

    #[DataProvider(ReapplyServiceProvider::class . '::unmatchedFilters')]
    public function unmatchedFilterIsAnError(string $file, string $provider): void
    {
        $project = (new InspectionProject($this->tempDir))->composer(['p/a']);

        $project->lock()->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out, $file, $provider),
            1,
            "Exit code must be '1' when no tracked file matches.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_NO_MATCH->getMessage() . PHP_EOL,
            'No-match error must be the only stderr line.',
        );
        Assert::same(
            $out->stdoutBuffer,
            '',
            'No summary may be printed.',
        );
    }

    /**
     * @param array<string, string> $extra Extra lock entry keys of the unsafe entry.
     */
    #[DataProvider(ReapplyServiceProvider::class . '::unsafeEntries')]
    public function unsafeEntryContinuesToTheNextEntry(
        string $destination,
        array $extra,
        string $kind,
        string $value,
    ): void {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['a.txt' => "a\n", 'b.txt' => "b2\n"])
            ->file('a.txt', "a\n")
            ->file('b.txt', "b1\n")
            ->track($destination, 'p/a', extra: $extra)
            ->track('b.txt', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out),
            1,
            "Exit code must be '1' for an unsafe entry.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_UNSAFE_ENTRY->getMessage(
                $destination,
                Message::PATH_TRAVERSAL->getMessage($kind, $value),
            ) . PHP_EOL,
            'Unsafe entry must be reported.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('b.txt') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 0, 1) . PHP_EOL,
            "'b.txt' must still be reapplied.",
        );
    }

    public function userModifiedSkipContinuesToTheNextEntry(): void
    {
        $project = (new InspectionProject($this->tempDir))
            ->composer(['p/a'])
            ->provider('p/a', ['config/params.php' => "stub\n", 'config/web.php' => "stub\n"])
            ->file('config/params.php', "stub\n")
            ->file('config/web.php', "stub\n")
            ->track('config/params.php', 'p/a')
            ->track('config/web.php', 'p/a')
            ->file('config/params.php', "user-edited\n");

        $out = new BufferedOutputWriter();

        Assert::same(
            $this->reapply($project, $out),
            0,
            "Exit code of a user-modified skip must be '0'.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::REAPPLY_USER_MODIFIED->getMessage('config/params.php') . PHP_EOL,
            'User-modified skip must be reported.',
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::REAPPLY_APPLIED->getMessage('config/web.php') . PHP_EOL
            . ExpectedSummary::reapply(1, 0, 1, 0, 'user-modified') . PHP_EOL,
            'Next entry must still be reapplied.',
        );
        FileAssert::contentSame(
            "{$this->tempDir}/config/params.php",
            "user-edited\n",
            'User content must be kept without force.',
        );
    }

    /**
     * Runs `reapply` over the project without dry run.
     */
    private function reapply(
        InspectionProject $project,
        BufferedOutputWriter $out,
        string $file = '',
        string $provider = '',
        bool $force = false,
    ): int {
        return (new ReapplyService())->run($project->context(), $out, $file, $provider, $force);
    }

    /**
     * Returns the lock entry recorded after `$destination` of provider `p/a` was written from a stub with `$stub`.
     *
     * @return array<string, string> Expected `replace` lock entry.
     */
    private static function writtenEntry(string $destination, string $stub): array
    {
        $hash = 'sha256:' . hash('sha256', $stub);

        return [
            'hash' => $hash,
            'provider' => 'p/a',
            'source' => "stubs/{$destination}",
            'mode' => 'replace',
            'stub-hash' => $hash,
        ];
    }
}
