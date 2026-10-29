<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Services;

use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Scaffold\PathResolver;
use PHPForge\Scaffold\Services\DiffService;
use PHPForge\Scaffold\Tests\Support\{BufferedOutputWriter, InspectionProject, TempDirectoryTrait};
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

use function dirname;
use function file_get_contents;
use function mkdir;
use function preg_quote;
use function realpath;
use function symlink;
use function unlink;

/**
 * Unit tests for {@see DiffService} rendering what applying the provider would change, per mode.
 */
#[Test]
#[Covers(DiffService::class)]
final class DiffServiceTest
{
    use TempDirectoryTrait;

    public function allDiffsTrackedFilesInLockOrderAndReportsOrphans(): void
    {
        $project = $this->project()
            ->provider('p/a', ['b.txt' => "b2\n", 'a.txt' => "a\n", 'gone.txt' => "g\n", 'e.txt' => "e\n"])
            ->file('a.txt', "a\n")
            ->file('b.txt', "b\n")
            ->file('gone.txt', "g\n")
            ->file('e.txt', "mine\n")
            ->track('b.txt', 'p/a')
            ->track('a.txt', 'p/a')
            ->track('gone.txt', 'p/a')
            ->track('e.txt', 'p/a', extra: ['ejected' => true])
            ->provider('p/a', ['b.txt' => "b2\n", 'a.txt' => "a\n", 'e.txt' => "e\n"]);

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out),
            0,
            "Exit code must be '0': orphans and changes are not errors.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_NO_CHANGES->getMessage('a.txt') . PHP_EOL
            . ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('b.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/b.txt\n+++ b/b.txt\n@@ -1 +1 @@\n-b\n+b2" . PHP_EOL
            . ConsoleMessage::DIFF_ORPHAN->getMessage('gone.txt') . PHP_EOL,
            'Files must follow lock order; ejected files are skipped.',
        );
        Assert::same(
            $out->stderrBuffer,
            '',
            'Nothing may be written to stderr.',
        );
    }

    public function allKeepsCountingErrorsAcrossOrphansAndDiffsLaterFiles(): void
    {
        $project = $this->project()
            ->provider('p/a', ['c.txt' => "c2\n"])
            ->provider('p/denied', ['a-denied.txt' => "d\n"])
            ->file('a-denied.txt', "d\n")
            ->file('b-gone.txt', "g\n")
            ->file('c.txt', "c\n")
            ->track('a-denied.txt', 'p/denied')
            ->track('b-gone.txt', 'p/a')
            ->track('c.txt', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out),
            1,
            "Exit code must stay '1' after an orphan.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_ORPHAN->getMessage('b-gone.txt') . PHP_EOL
            . ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('c.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/c.txt\n+++ b/c.txt\n@@ -1 +1 @@\n-c\n+c2" . PHP_EOL,
            'Files after an orphan must still be diffed.',
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::COMMAND_PROVIDER_NOT_ALLOWED->getMessage('a-denied.txt', 'p/denied') . PHP_EOL,
            'Not-allowed entry must be reported.',
        );
    }

    public function allKeepsDiffingAfterAFileThatCannotBeRead(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n", 'b.txt' => "b2\n"])
            ->file('b.txt', "b\n")
            ->track('a.txt', 'p/a')
            ->track('b.txt', 'p/a');

        // A directory where the destination file is expected cannot be read as a file.
        mkdir("{$this->tempDir}/a.txt");

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out),
            1,
            "Exit code must be '1' when a file cannot be read.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('b.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/b.txt\n+++ b/b.txt\n@@ -1 +1 @@\n-b\n+b2" . PHP_EOL,
            'Next file must still be diffed.',
        );
        Assert::string($out->stderrBuffer)->matchesRegex(
            $this->readFailurePattern('a.txt'),
            'Read failure must be the only stderr line.',
        );
    }

    public function appendEditorconfigEvidenceShowsOnlyTheMissingSectionAtTheEnd(): void
    {
        $project = $this->seedEvidence('.editorconfig', 'ini/provider.txt', 'ini/consumer-scaffold.txt');

        $out = new BufferedOutputWriter();

        (new DiffService())
            ->run($project->context(), $out, '.editorconfig');

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('.editorconfig', 'append', 'php-forge/baseline', '')
            . PHP_EOL
            . "--- a/.editorconfig\n+++ b/.editorconfig\n@@ -45,3 +45,6 @@ indent_size = 2\n"
            . " [LICENSE*]\n indent_style = unset\n indent_size = unset\n+\n+[*.js]\n+indent_size = 2" . PHP_EOL,
            "Only the missing '[*.js]' section may be added.",
        );
    }

    public function appendGitignoreEvidenceShowsOnlyTheMissingBlock(): void
    {
        $project = $this->seedEvidence('.gitignore', 'list/provider-v1.txt', 'list/consumer-scaffold.txt');

        $out = new BufferedOutputWriter();

        (new DiffService())
            ->run($project->context(), $out, '.gitignore');

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('.gitignore', 'append', 'php-forge/baseline', '') . PHP_EOL
            . "--- a/.gitignore\n+++ b/.gitignore\n@@ -56,3 +56,6 @@ Thumbs.db\n .buildpath\n .project\n .settings\n"
            . "+\n+# Local AI agent, editor, and GitHub CLI config (if present)\n+.config/gh" . PHP_EOL,
            "Only the '.config/gh' block may be added.",
        );
    }

    public function appendSatisfiedReportsNoChanges(): void
    {
        $project = $this->project()
            ->provider('p/a', ['.gitignore' => "/vendor\n"], ['.gitignore' => 'append'])
            ->file('.gitignore', "/my-build\n/vendor\n")
            ->track('.gitignore', 'p/a', 'append');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out, '.gitignore', exitCode: true),
            0,
            "Exit code must be '0' when nothing would change.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_NO_CHANGES->getMessage('.gitignore') . PHP_EOL,
            'No-change line must be the only output.',
        );
    }

    public function crlfOnlyDifferenceIsReported(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\nb\n"])
            ->file('a.txt', "a\r\nb\r\n")
            ->track('a.txt', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out, 'a.txt', exitCode: true),
            1,
            "Exit code must be '1' when line endings would change.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_LINE_ENDINGS_ONLY->getMessage('a.txt') . PHP_EOL,
            'Line-ending notice must be the only output.',
        );
    }

    public function decoratedOutputColorsTheDiffButNotTheHeader(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "new\n"])
            ->file('a.txt', "old\n")
            ->track('a.txt', 'p/a');

        $out = new BufferedOutputWriter();

        (new DiffService())
            ->run($project->context(), $out, 'a.txt', true);

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('a.txt', 'replace', 'p/a', '') . PHP_EOL
            . "\e[1m--- a/a.txt\e[0m\n\e[1m+++ b/a.txt\e[0m\n\e[36m@@ -1 +1 @@\e[0m\n\e[31m-old\e[0m\n\e[32m+new\e[0m"
            . PHP_EOL,
            'Only the diff lines must be colored.',
        );
    }

    public function ejectedFileIsReportedWithoutDiff(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "mine\n")
            ->track('a.txt', 'p/a', extra: ['ejected' => true]);

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out, 'a.txt'),
            0,
            "Exit code must be '0' for an ejected file.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_EJECTED->getMessage('a.txt') . PHP_EOL,
            'Ejected line must be the only output.',
        );
    }

    public function missingDestinationDiffsFromEmpty(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->delete('a.txt');

        $out = new BufferedOutputWriter();

        (new DiffService())
            ->run($project->context(), $out, 'a.txt');

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('a.txt', 'replace', 'p/a', '') . PHP_EOL
            . "--- a/a.txt\n+++ b/a.txt\n@@ -0,0 +1 @@\n+a" . PHP_EOL,
            'Missing file must diff from empty.',
        );
    }

    public function missingStubAndUnsafeEntriesAreErrors(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->track('../evil', 'p/a')
            ->delete('vendor/p/a/stubs/a.txt');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out),
            1,
            "Exit code must be '1' when entries fail.",
        );
        Assert::same(
            $out->stderrBuffer,
            Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage(
                'p/a',
                Message::MANIFEST_COPY_ENTRY_NOT_FOUND->getMessage(
                    'stubs/a.txt',
                    (string) realpath("{$this->tempDir}/vendor/p/a"),
                ),
            ) . PHP_EOL
            . ConsoleMessage::COMMAND_UNSAFE_ENTRY->getMessage(
                '../evil',
                Message::PATH_TRAVERSAL->getMessage('destination', '../evil'),
            ) . PHP_EOL
            . ConsoleMessage::DIFF_MANIFEST_NOT_LOADED->getMessage('a.txt', 'p/a') . PHP_EOL,
            'Manifest warning, unsafe entry and skipped entry must be reported in order.',
        );
        Assert::same(
            $out->stdoutBuffer,
            '',
            'Nothing may be diffed.',
        );
    }

    public function notAllowedProviderIsRejected(): void
    {
        $project = $this->project()
            ->provider('p/denied', ['d.txt' => "d\n"])
            ->file('d.txt', "x\n")
            ->track('d.txt', 'p/denied');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out, 'd.txt'),
            1,
            "Exit code must be '1' for a rejected entry.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::COMMAND_PROVIDER_NOT_ALLOWED->getMessage('d.txt', 'p/denied') . PHP_EOL,
            'Allowlist message must be the only stderr line.',
        );
        Assert::same(
            $out->stdoutBuffer,
            '',
            'Nothing may be diffed.',
        );
    }

    public function notTrackedFileIsAnError(): void
    {
        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($this->project()->context(), $out, 'nope.txt'),
            1,
            "Exit code must be '1' for an untracked file.",
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::COMMAND_FILE_NOT_TRACKED->getMessage('nope.txt') . PHP_EOL,
            'Error must be the only stderr line.',
        );
        Assert::same(
            $out->stdoutBuffer,
            '',
            'Nothing may be diffed.',
        );
    }

    public function prependShowsTheBlockAddedOnTop(): void
    {
        $project = $this->project()
            ->provider('p/a', ['h.php' => "<?php\n"], ['h.php' => 'prepend'])
            ->file('h.php', "echo 1;\n")
            ->track('h.php', 'p/a', 'prepend');

        $out = new BufferedOutputWriter();

        (new DiffService())
            ->run($project->context(), $out, 'h.php');

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('h.php', 'prepend', 'p/a', '') . PHP_EOL
            . "--- a/h.php\n+++ b/h.php\n@@ -1 +1,2 @@\n+<?php\n echo 1;" . PHP_EOL,
            'Prepended block must be shown.',
        );
    }

    public function preserveDiffIsLabelledInformational(): void
    {
        $project = $this->project()
            ->provider('p/a', ['ecs.php' => "stub\n"], ['ecs.php' => 'preserve'])
            ->file('ecs.php', "mine\n")
            ->track('ecs.php', 'p/a', 'preserve');

        $out = new BufferedOutputWriter();

        (new DiffService())
            ->run($project->context(), $out, 'ecs.php');

        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage(
                'ecs.php',
                'preserve',
                'p/a',
                ConsoleMessage::FRAGMENT_PRESERVE_PREVIEW->getMessage(),
            ) . PHP_EOL . "--- a/ecs.php\n+++ b/ecs.php\n@@ -1 +1 @@\n-mine\n+stub" . PHP_EOL,
            'Preserve diff must be labelled.',
        );
    }

    public function replaceUserModifiedFileGetsSuffix(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "stub\n"])
            ->file('a.txt', "stub\n")
            ->track('a.txt', 'p/a')
            ->file('a.txt', "mine\n");

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out, 'a.txt', exitCode: true),
            1,
            "Exit code must be '1' when a change exists.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage(
                'a.txt',
                'replace',
                'p/a',
                ConsoleMessage::FRAGMENT_USER_MODIFIED_PREVIEW->getMessage(),
            ) . PHP_EOL . "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1 @@\n-mine\n+stub" . PHP_EOL,
            'User-modified suffix must be shown.',
        );
    }

    public function unloadableManifestIsWarnedAndIsAnError(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->file('vendor/p/a/composer.json', '{"extra": {"scaffold": {"copy": []}}}');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out, 'a.txt'),
            1,
            "Exit code must be '1' when the manifest cannot be loaded.",
        );
        Assert::same(
            $out->stderrBuffer,
            Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage('p/a', Message::MANIFEST_COPY_EMPTY->getMessage())
            . PHP_EOL
            . ConsoleMessage::DIFF_MANIFEST_NOT_LOADED->getMessage('a.txt', 'p/a') . PHP_EOL,
            'Manifest warning must precede the entry error.',
        );
    }

    public function unreadableDestinationIsAnErrorAndOtherFilesAreIgnored(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n", 'b.txt' => "b\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->track('b.txt', 'p/a');

        // A directory where the destination file is expected cannot be read as a file.
        mkdir("{$this->tempDir}/b.txt");

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out, 'b.txt'),
            1,
            "Exit code must be '1' when the file cannot be read.",
        );
        Assert::same(
            $out->stdoutBuffer,
            '',
            'Other files must not be diffed.',
        );
        Assert::string($out->stderrBuffer)->matchesRegex(
            $this->readFailurePattern('b.txt'),
            'Read failure must be reported with its reason.',
        );
    }

    public function unsafeStubSymlinkIsAnError(): void
    {
        $project = $this->project()
            ->provider('p/a', ['a.txt' => "a\n"])
            ->file('a.txt', "a\n")
            ->track('a.txt', 'p/a')
            ->file('outside.txt', "outside\n");

        unlink("{$this->tempDir}/vendor/p/a/stubs/a.txt");

        if (@symlink("{$this->tempDir}/outside.txt", "{$this->tempDir}/vendor/p/a/stubs/a.txt") === false) {
            throw new SkipTest('Symbolic links are not available.');
        }

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out, 'a.txt'),
            1,
            "Exit code must be '1' for an escaping stub.",
        );
        Assert::same(
            $out->stdoutBuffer,
            '',
            'Nothing may be diffed.',
        );
        Assert::same(
            $out->stderrBuffer,
            ConsoleMessage::COMMAND_UNSAFE_ENTRY->getMessage(
                'a.txt',
                Message::PATH_SYMLINK_ESCAPE->getMessage('Source', 'stubs/a.txt'),
            ) . PHP_EOL,
            'Unsafe source must be the only stderr line.',
        );
    }
    public function wouldBeCreatedEmptyIsReported(): void
    {
        $project = $this->project()
            ->provider('p/a', ['empty.txt' => ''])
            ->track('empty.txt', 'p/a');

        $out = new BufferedOutputWriter();

        Assert::same(
            (new DiffService())->run($project->context(), $out, 'empty.txt', exitCode: true),
            1,
            "Exit code must be '1': creating an empty file is a change.",
        );
        Assert::same(
            $out->stdoutBuffer,
            ConsoleMessage::DIFF_PREVIEW_HEADER->getMessage('empty.txt', 'replace', 'p/a', '') . PHP_EOL
            . ConsoleMessage::DIFF_CREATED_EMPTY->getMessage('empty.txt') . PHP_EOL,
            'Empty file creation must be reported.',
        );
    }

    /**
     * Returns the builder of the temporary project, with `p/a` and `php-forge/baseline` allowed.
     */
    private function project(): InspectionProject
    {
        return (new InspectionProject($this->tempDir))->composer(['p/a', 'php-forge/baseline']);
    }

    /**
     * Returns the pattern of the only stderr line reporting that `$destination`, a directory, cannot be read.
     *
     * The PHP notice depends on the filesystem and the platform, so only its function name is matched.
     */
    private function readFailurePattern(string $destination): string
    {
        $prefix = ConsoleMessage::COMMAND_FILE_ERROR->getMessage(
            $destination,
            Message::SERVICE_CURRENT_FILE_UNREADABLE->getMessage(PathResolver::destination($this->tempDir, $destination))
            . ' Reason: file_get_contents(',
        );

        return '/\A' . preg_quote($prefix, '/') . '.+\R\z/';
    }

    /**
     * Tracks an `append` file built from the owner's merge evidence fixtures.
     */
    private function seedEvidence(string $destination, string $provider, string $consumer): InspectionProject
    {
        $fixtures = dirname(__DIR__) . '/Fixtures/merge';

        return $this->project()
            ->provider(
                'php-forge/baseline',
                [$destination => (string) file_get_contents("{$fixtures}/{$provider}")],
                [$destination => 'append'],
            )
            ->file($destination, (string) file_get_contents("{$fixtures}/{$consumer}"))
            ->track($destination, 'php-forge/baseline', 'append');
    }
}
