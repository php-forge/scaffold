<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold\Modes;

use PHPForge\Scaffold\Manifest\{FileMapping, FileMode};
use PHPForge\Scaffold\Scaffold\Lock\Hasher;
use PHPForge\Scaffold\Scaffold\Modes\{
    AppendMode,
    ApplyOutcome,
    ApplyResult,
    ModeInterface,
    PrependMode,
    PreserveMode,
    ReplaceMode,
};
use PHPForge\Scaffold\Scaffold\PathResolver;
use PHPForge\Scaffold\Tests\Provider\ModeProvider;
use PHPForge\Scaffold\Tests\Support\{InspectionProject, TempDirectoryTrait};
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

use function chmod;
use function file_get_contents;
use function fileperms;
use function mkdir;
use function preg_quote;
use function str_replace;
use function umask;

/**
 * Unit tests for the {@see ModeInterface} contract shared by the four scaffold modes: writing a missing destination
 * and reporting every filesystem failure.
 *
 * {@see ModeProvider} for test case data providers.
 */
#[Test]
#[Covers(AppendMode::class)]
#[Covers(PrependMode::class)]
#[Covers(PreserveMode::class)]
#[Covers(ReplaceMode::class)]
#[Covers(ApplyResult::class)]
final class ModeContractTest
{
    use TempDirectoryTrait;

    #[DataProvider(ModeProvider::class . '::copyModes')]
    public function copiesSourcePermissionBitsOntoDestination(ModeInterface $mode, FileMode $fileMode): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('POSIX umask-based permissions do not apply to NTFS.');
        }

        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', '#!/bin/sh');

        chmod("{$this->tempDir}/vendor/p/a/stubs/output.txt", 0o755);

        $umask = umask(0o022);

        try {
            $result = $mode->apply($this->mapping($fileMode), $this->tempDir, null);
        } finally {
            umask($umask);
        }

        Assert::same(
            [fileperms("{$this->tempDir}/output.txt") & 0o777, $result->warning],
            [0o755, null],
            "Destination must carry the source mode '0755' without a warning.",
        );
    }

    #[DataProvider(ModeProvider::class . '::unreadableDestinations')]
    public function throwRuntimeExceptionWhenDestinationIsADirectory(
        ModeInterface $mode,
        FileMode $fileMode,
        string $message,
    ): void {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', 'stub');

        mkdir("{$this->tempDir}/output.txt");

        Expect::exception(RuntimeException::class)
            ->withMessageMatchingRegex(
                '/^' . preg_quote($this->paths($message), '/') . '/',
            );

        $mode->apply($this->mapping($fileMode), $this->tempDir, null);
    }

    #[DataProvider(ModeProvider::class . '::unwritableDestinations')]
    public function throwRuntimeExceptionWhenDestinationIsNotWritable(
        ModeInterface $mode,
        FileMode $fileMode,
        string $message,
    ): void {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'output.txt', 'stub');

        $this->createDanglingLink("{$this->tempDir}/output.txt");

        Expect::exception(RuntimeException::class)
            ->withMessage(
                $this->paths($message),
            );

        $mode->apply($this->mapping($fileMode), $this->tempDir, null);
    }

    #[DataProvider(ModeProvider::class . '::missingSources')]
    public function throwRuntimeExceptionWhenSourceIsMissing(
        ModeInterface $mode,
        FileMode $fileMode,
        string $message,
    ): void {
        Expect::exception(RuntimeException::class)
            ->withMessage(
                $this->paths($message),
            );

        $mode->apply($this->mapping($fileMode), $this->tempDir, null);
    }

    #[DataProvider(ModeProvider::class . '::missingDestinations')]
    public function writesMissingDestinationWithParentDirectories(
        ModeInterface $mode,
        FileMode $fileMode,
        string|null $lockHash,
    ): void {
        (new InspectionProject($this->tempDir))
            ->stub('p/a', 'a/b/c/deep.txt', 'hello');

        $result = $mode->apply($this->mapping($fileMode, 'a/b/c/deep.txt'), $this->tempDir, $lockHash);

        $path = "{$this->tempDir}/a/b/c/deep.txt";

        Assert::same(
            [$result->outcome, file_get_contents($path), $result->newHash, $result->warning],
            [ApplyOutcome::Written, 'hello', Hasher::hash($path), null],
            "Missing destination must be 'Written' with the stub content, its hash and no warning.",
        );
    }

    /**
     * Returns the mapping of `$destination` to the stub of the same name in the `p/a` provider.
     *
     * @param FileMode $fileMode Mode recorded in the mapping.
     * @param string $destination Destination path relative to the project root.
     *
     * @return FileMapping Mapping of the destination.
     */
    private function mapping(FileMode $fileMode, string $destination = 'output.txt'): FileMapping
    {
        return new FileMapping(
            $destination,
            "stubs/{$destination}",
            $fileMode,
            'p/a',
            "{$this->tempDir}/vendor/p/a",
        );
    }

    /**
     * Replaces the `{source}` and `{destination}` placeholders with the absolute paths of the default mapping.
     *
     * @param string $message Message with placeholders.
     *
     * @return string Message with absolute paths.
     */
    private function paths(string $message): string
    {
        return str_replace(
            ['{source}', '{destination}'],
            [
                PathResolver::source("{$this->tempDir}/vendor/p/a", 'stubs/output.txt'),
                PathResolver::destination($this->tempDir, 'output.txt'),
            ],
            $message,
        );
    }
}
