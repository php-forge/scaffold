<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold;

use PHPForge\Scaffold\Config\ProjectConfig;
use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Scaffold\FileApplier;
use PHPForge\Scaffold\Scaffold\Modes\{ApplyOutcome, PreserveMode, ReplaceMode};
use PHPForge\Scaffold\Security\PathValidator;
use PHPForge\Scaffold\Tests\Provider\FileApplierProvider;
use PHPForge\Scaffold\Tests\Support\{BufferedOutputWriter, FileAssert, InspectionProject, TempDirectoryTrait};
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

use function hash;

/**
 * Unit tests for {@see FileApplier} security pre-checks, mode delegation and warning forwarding.
 *
 * {@see FileApplierProvider} for test case data providers.
 */
#[Test]
#[Covers(FileApplier::class)]
final class FileApplierTest
{
    use TempDirectoryTrait;

    /**
     * Ensures the mode warning is forwarded to stderr with the scaffold prefix.
     */
    public function forwardsModeWarningToStderr(): void
    {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', 'stub content')
            ->file('output.txt', 'user-modified content');

        $out = new BufferedOutputWriter();

        $result = $this->applier($out)->apply(
            $this->mapping('output.txt', 'stubs/output.txt', 'p/a'),
            $this->tempDir,
            new ReplaceMode(),
            'sha256:' . hash('sha256', 'completely-different-content'),
        );

        Assert::same(
            [$result->outcome, $out->stderrBuffer],
            [
                ApplyOutcome::Skipped,
                Message::SCAFFOLD_PREFIXED->getMessage(
                    Message::SCAFFOLD_USER_MODIFIED_SKIPPED->getMessage('output.txt'),
                ) . PHP_EOL,
            ],
            "User-modified file must be 'Skipped' and its warning written to stderr.",
        );
    }

    /**
     * Ensures the mode result is returned and nothing is written to stderr when the mode has no warning.
     */
    public function returnsModeResultWithoutOutputWhenModeHasNoWarning(): void
    {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', 'stub')
            ->file('output.txt', 'existing');

        $out = new BufferedOutputWriter();

        $result = $this->applier($out)->apply(
            $this->mapping('output.txt', 'stubs/output.txt', 'p/a'),
            $this->tempDir,
            new PreserveMode(),
            null,
        );

        FileAssert::contentSame("{$this->tempDir}/output.txt", 'existing', 'Existing file must not be overwritten.');
        Assert::same(
            [$result->outcome, $out->stderrBuffer],
            [ApplyOutcome::Skipped, ''],
            "Preserved file must be 'Skipped' without any stderr output.",
        );
    }

    /**
     * Ensures a mapping of a provider that is not allowed, or with a traversal path, is rejected before any mode runs.
     */
    #[DataProvider(FileApplierProvider::class . '::rejectedMappings')]
    public function throwRuntimeExceptionForRejectedMapping(
        string $destination,
        string $source,
        string $providerName,
        string $message,
    ): void {
        Expect::exception(RuntimeException::class)
            ->withMessage($message);

        $this->applier(new BufferedOutputWriter())->apply(
            $this->mapping($destination, $source, $providerName),
            $this->tempDir,
            new ReplaceMode(),
            null,
        );
    }

    /**
     * Creates a {@see FileApplier} that allows the `p/a` provider and writes warnings to `$out`.
     *
     * @param BufferedOutputWriter $out Output sink of the mode warnings.
     *
     * @return FileApplier Applier configured for the test.
     */
    private function applier(BufferedOutputWriter $out): FileApplier
    {
        return new FileApplier(new ProjectConfig(['p/a']), new PathValidator(), $out);
    }

    /**
     * Returns the replace mapping of `$destination` to `$source` in the provider directory of `p/a`.
     *
     * @param string $destination Destination path relative to the project root.
     * @param string $source Source path relative to the provider root.
     * @param string $providerName Provider package name.
     *
     * @return FileMapping Mapping used for the test.
     */
    private function mapping(string $destination, string $source, string $providerName): FileMapping
    {
        return new FileMapping($destination, $source, FileMode::Replace, $providerName, "{$this->tempDir}/vendor/p/a");
    }
}
