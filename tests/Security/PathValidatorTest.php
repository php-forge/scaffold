<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Security;

use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Security\PathValidator;
use PHPForge\Scaffold\Tests\Provider\PathValidatorProvider;
use PHPForge\Scaffold\Tests\Support\TempDirectoryTrait;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\{DataProvider, DataSet};
use Testo\Expect;
use Testo\Test;

use function realpath;
use function str_replace;
use function symlink;

/**
 * Unit tests for {@see PathValidator} path traversal, absolute path, symlink escape and reserved destination detection.
 *
 * {@see PathValidatorProvider} for test case data providers.
 */
#[Test]
#[Covers(PathValidator::class)]
final class PathValidatorTest
{
    use TempDirectoryTrait;

    /**
     * @param 'destination'|'source' $kind Validated path kind.
     * @param list<string> $directories Directories to create besides the root, relative to the temporary directory.
     * @param array<string, string> $links Symbolic link targets keyed by link path.
     */
    #[DataProvider(PathValidatorProvider::class . '::acceptedPaths')]
    public function acceptsPathInsideRoot(string $kind, string $path, array $directories, array $links): void
    {
        $this->seed($directories, $links);

        Assert::same(
            $this->validate($kind, $path),
            realpath("{$this->tempDir}/root"),
            'Accepted path must yield the canonical root.',
        );
    }

    #[DataProvider(PathValidatorProvider::class . '::paths')]
    public function classifiesAbsoluteAndTraversalPaths(string $path, bool $absolute, bool $traversal): void
    {
        Assert::same(
            [PathValidator::isAbsolute($path), PathValidator::hasTraversal($path)],
            [$absolute, $traversal],
            "Absolute and traversal classification of '{$path}' must match.",
        );
    }

    /**
     * @param 'destination'|'source' $kind Validated path kind.
     * @param list<string> $directories Directories to create besides the root, relative to the temporary directory.
     * @param array<string, string> $links Symbolic link targets keyed by link path.
     */
    #[DataProvider(PathValidatorProvider::class . '::rejectedPaths')]
    public function throwRuntimeExceptionForRejectedPath(
        string $kind,
        string $path,
        array $directories,
        array $links,
        string $message,
    ): void {
        $this->seed($directories, $links);

        Expect::exception(RuntimeException::class)
            ->withMessage(
                $message,
            );

        $this->validate($kind, $path);
    }

    /**
     * @param 'destination'|'source' $kind Validated path kind.
     */
    #[DataSet(['destination', Message::PATH_PROJECT_ROOT_INACCESSIBLE], 'project root')]
    #[DataSet(['source', Message::PATH_PROVIDER_ROOT_INACCESSIBLE], 'provider root')]
    public function throwRuntimeExceptionWhenRootIsMissing(string $kind, Message $message): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessage(
                $message->getMessage("{$this->tempDir}/root"),
            );

        $this->validate($kind, 'config/params.php');
    }

    /**
     * Creates the root, the extra directories and the symbolic links, skipping on Windows when links are needed.
     *
     * @param list<string> $directories Directories to create besides the root, relative to the temporary directory.
     * @param array<string, string> $links Symbolic link targets keyed by link path, both relative to the temporary
     * directory unless the target starts with `{tmp}`.
     */
    private function seed(array $directories, array $links): void
    {
        if ($links !== [] && DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest(
                'Symbolic link resolution is only verified on POSIX.',
            );
        }

        foreach (['root', ...$directories] as $directory) {
            $this->ensureTestDirectory("{$this->tempDir}/{$directory}");
        }

        foreach ($links as $link => $target) {
            symlink(str_replace('{tmp}', $this->tempDir, $target), "{$this->tempDir}/{$link}");
        }
    }

    /**
     * Validates `$path` as a destination or a source against the `root` directory.
     *
     * @param 'destination'|'source' $kind Validated path kind.
     * @param string $path Path relative to the root.
     *
     * @return string Canonical root returned by the validator.
     */
    private function validate(string $kind, string $path): string
    {
        $validator = new PathValidator();

        return $kind === 'destination'
            ? $validator->validateDestination($path, "{$this->tempDir}/root")
            : $validator->validateSource($path, "{$this->tempDir}/root");
    }
}
