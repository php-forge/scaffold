<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Scaffold;

use PHPForge\Scaffold\Console\Message as ConsoleMessage;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Scaffold\PathResolver;
use PHPForge\Scaffold\Tests\Provider\PathResolverProvider;
use PHPForge\Scaffold\Tests\Support\{FileAssert, TempDirectoryTrait};
use ReflectionMethod;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

use function chmod;
use function error_clear_last;
use function error_get_last;
use function file_put_contents;
use function fileperms;
use function function_exists;
use function is_array;
use function is_file;
use function is_string;
use function mkdir;
use function posix_geteuid;
use function preg_quote;
use function realpath;
use function str_replace;
use function umask;
use function uniqid;

/**
 * Unit tests for {@see PathResolver} path joining, directory creation, permission copying and provider-root
 * resolution.
 *
 * {@see PathResolverProvider} for test case data providers.
 */
#[Test]
#[Covers(PathResolver::class)]
final class PathResolverTest
{
    use TempDirectoryTrait;

    public function ensureDirectoryCreatesParentWithUmaskAppliedMode(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('POSIX umask-based permissions do not apply to NTFS.');
        }

        $umask = umask(0o022);

        try {
            PathResolver::ensureDirectory("{$this->tempDir}/fresh/file.txt");
        } finally {
            umask($umask);
        }

        Assert::same(
            fileperms("{$this->tempDir}/fresh") & 0o777,
            0o755,
            "Directory created under umask '0022' must have mode '0755'.",
        );
    }

    public function ensureDirectoryKeepsExistingParent(): void
    {
        mkdir("{$this->tempDir}/existing");

        PathResolver::ensureDirectory("{$this->tempDir}/existing/file.txt");

        FileAssert::directoryExists(
            "{$this->tempDir}/existing",
            'Existing parent directory must be kept.',
        );
    }

    /**
     * @param string $path Absolute path to check.
     * @param string $directory Absolute path of the containing directory.
     * @param bool $caseInsensitive Whether to compare ignoring case.
     * @param bool $expected Whether `$path` is expected inside `$directory`.
     */
    #[DataProvider(PathResolverProvider::class . '::within')]
    public function isWithinHonorsSeparatorsAndCaseInsensitiveFlag(
        string $path,
        string $directory,
        bool $caseInsensitive,
        bool $expected,
    ): void {
        Assert::same(
            (new ReflectionMethod(PathResolver::class, 'isWithin'))->invoke(null, $path, $directory, $caseInsensitive),
            $expected,
            'Containment check must treat both separators alike and honor the case flag.',
        );
    }

    /**
     * @param 'destination'|'source' $method Join method.
     */
    #[DataProvider(PathResolverProvider::class . '::joins')]
    public function joinsPathsWithOneSeparator(string $method, string $base, string $relative, string $expected): void
    {
        Assert::same(
            $method === 'destination'
                ? PathResolver::destination($base, $relative)
                : PathResolver::source($base, $relative),
            $expected,
            'Joined path must hold one separator between its parts.',
        );
    }

    public function realpathOrFallbackCanonicalizesExistingPathsAndTrimsMissingOnes(): void
    {
        $missing = '/nonexistent/path/' . uniqid();

        Assert::same(
            [PathResolver::realpathOrFallback($this->tempDir), PathResolver::realpathOrFallback("{$missing}/\\")],
            [realpath($this->tempDir), $missing],
            'Existing path must be canonical; missing path must lose its trailing separators.',
        );
    }

    /**
     * @param list<string> $directories Directories to create, relative to the temporary directory.
     */
    #[DataProvider(PathResolverProvider::class . '::providerRoots')]
    public function resolveProviderRootHonorsOnlyLockPathsInsideVendor(
        array $directories,
        string $vendorDir,
        mixed $lock,
        string $projectRoot,
        string $root,
        string|null $warning,
    ): void {
        foreach ($directories as $directory) {
            $this->ensureTestDirectory("{$this->tempDir}/{$directory}");
        }

        if (is_array($lock) && is_string($lock['path'] ?? null)) {
            $lock['path'] = $this->expand($lock['path']);
        }

        Assert::same(
            PathResolver::resolveProviderRoot(
                $this->expand($vendorDir),
                'pkg/name',
                $lock,
                $this->expand($projectRoot),
            ),
            ['root' => $this->expand($root), 'warning' => $warning],
            'Resolved root and warning must match.',
        );
    }

    #[DataProvider(PathResolverProvider::class . '::permissions')]
    public function syncPermissionsCopiesSourceBitsMaskedByUmask(
        int $sourceMode,
        int $destinationMode,
        int $umask,
        int $expected,
    ): void {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('POSIX umask-based permissions do not apply to NTFS.');
        }

        file_put_contents(
            "{$this->tempDir}/source.sh",
            "#!/bin/sh\n",
        );
        file_put_contents(
            "{$this->tempDir}/copy.sh",
            "#!/bin/sh\n",
        );
        chmod("{$this->tempDir}/source.sh", $sourceMode);
        chmod("{$this->tempDir}/copy.sh", $destinationMode);

        $previous = umask($umask);

        try {
            $warning = PathResolver::syncPermissions("{$this->tempDir}/source.sh", "{$this->tempDir}/copy.sh");
        } finally {
            umask($previous);
        }

        Assert::same(
            [fileperms("{$this->tempDir}/copy.sh") & 0o777, $warning],
            [$expected, null],
            'Destination bits must be the source bits masked by the umask, without a warning.',
        );
    }

    public function syncPermissionsReturnsChmodWarningInsteadOfRaisingIt(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0 || !is_file('/proc/version')) {
            throw new SkipTest(
                'Needs a non-root POSIX process and a file it does not own.',
            );
        }

        file_put_contents(
            "{$this->tempDir}/source.txt",
            'x',
        );
        error_clear_last();

        Assert::same(
            PathResolver::syncPermissions("{$this->tempDir}/source.txt", '/proc/version'),
            Message::FILESYSTEM_FAILED->getMessage(
                ConsoleMessage::SCAFFOLD_PERMISSIONS_COPY_FAILED->getMessage("{$this->tempDir}/source.txt", '/proc/version'),
                'chmod(): Operation not permitted',
            ),
            'PHP warning text must be returned, not raised.',
        );
        Assert::null(
            error_get_last(),
            'The warning must not reach the PHP error handler.',
        );
    }

    public function syncPermissionsReturnsWarningWhenSourceIsMissing(): void
    {
        file_put_contents("{$this->tempDir}/copy.sh", "#!/bin/sh\n");

        $before = fileperms("{$this->tempDir}/copy.sh");

        Assert::same(
            [
                PathResolver::syncPermissions("{$this->tempDir}/missing.sh", "{$this->tempDir}/copy.sh"),
                fileperms("{$this->tempDir}/copy.sh"),
            ],
            [
                Message::FILESYSTEM_FAILED->getMessage(
                    ConsoleMessage::SCAFFOLD_PERMISSIONS_COPY_FAILED->getMessage(
                        "{$this->tempDir}/missing.sh",
                        "{$this->tempDir}/copy.sh",
                    ),
                    "fileperms(): stat failed for {$this->tempDir}/missing.sh",
                ),
                $before,
            ],
            'Missing source must be reported and the destination bits must stay unchanged.',
        );
    }

    public function throwRuntimeExceptionWhenParentIsARegularFile(): void
    {
        file_put_contents(
            "{$this->tempDir}/blocker",
            'x',
        );

        $directory = "{$this->tempDir}/blocker/sub";

        Expect::exception(RuntimeException::class)
            ->withMessageMatchingRegex(
                '/\A'
                . preg_quote(Message::PATH_DIRECTORY_CREATE_FAILED->getMessage($directory) . ' Reason: mkdir(): ', '/')
                . '.+\.\z/',
            );

        PathResolver::ensureDirectory("{$directory}/file.txt");
    }

    /**
     * Replaces the `{tmp}` and `{real}` placeholders with the temporary directory and its canonical path, and `{/}`
     * with the platform separator that the resolver joins with.
     *
     * @param string $path Path with placeholders.
     *
     * @return string Path with the placeholders replaced.
     */
    private function expand(string $path): string
    {
        return str_replace(
            ['{tmp}', '{real}', '{/}'],
            [$this->tempDir, (string) realpath($this->tempDir), DIRECTORY_SEPARATOR],
            $path,
        );
    }
}
