<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Functional;

use Closure;
use Composer\IO\BufferIO;
use Composer\Package\{CompletePackage, PackageInterface};
use PHPForge\Scaffold\Config\ProjectConfig;
use PHPForge\Scaffold\Console\{ComposerIoWriter, Message as ConsoleMessage};
use PHPForge\Scaffold\Exception\{Message, ScaffoldException};
use PHPForge\Scaffold\Manifest\{ManifestExpander, ManifestLoader, ManifestSchema};
use PHPForge\Scaffold\Scaffold\FileApplier;
use PHPForge\Scaffold\Scaffold\Lock\{Hasher, LockFile};
use PHPForge\Scaffold\Scaffold\{ScaffoldRunner, ScaffoldSummary, Scaffolder};
use PHPForge\Scaffold\Security\PathValidator;
use PHPForge\Scaffold\Tests\Provider\ScaffolderProvider;
use PHPForge\Scaffold\Tests\Support\{FakeProjectBuilder, FileAssert, TempDirectoryTrait};
use ReflectionMethod;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataProvider;
use Testo\Test;

use function array_column;
use function array_filter;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function mkdir;
use function str_replace;
use function symlink;
use function unlink;

/**
 * Functional tests for {@see Scaffolder} end-to-end scaffold execution.
 *
 * {@see ScaffolderProvider} for test case data providers.
 */
#[Test]
final class ScaffolderTest
{
    use TempDirectoryTrait;

    /**
     * Ensures an adopted preserve file stays the user's when the provider switches it to replace mode.
     */
    public function adoptedPreserveFileIsSkippedWhenProviderFlipsItToReplace(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $io = new BufferIO();

        $builder->createStubFile('php-forge/test', 'ecs.php', "stub\n");
        $builder->createProjectFile('ecs.php', "mine\n");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [self::provider('php-forge/test', ['copy' => ['ecs.php'], 'modes' => ['ecs.php' => 'preserve']])],
        );

        Assert::true(
            self::lockEntry($builder, 'ecs.php')['adopted'] ?? false,
            "Existing file must be 'adopted'.",
        );

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [self::provider('php-forge/test', ['copy' => ['ecs.php']])],
            $io,
        );

        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/ecs.php",
            "mine\n",
            'User file must be kept.',
        );
        Assert::same(
            $io->getOutput(),
            ConsoleMessage::SCAFFOLD_ADOPTED_SKIPPED->getMessage('ecs.php') . PHP_EOL,
            'Skip must be reported.',
        );
        Assert::true(
            self::lockEntry($builder, 'ecs.php')['adopted'] ?? false,
            "Entry must stay 'adopted'.",
        );
    }

    /**
     * Ensures a skipped append file is attributed to the provider that now claims it.
     */
    public function appendModeRefreshesLockOwnershipWhenNewProviderClaimsExistingFile(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $manifest = [
            'copy' => ['.gitignore'],
            'modes' => ['.gitignore' => 'append'],
        ];

        $builder->createStubFile('php-forge/old-provider', '.gitignore', "*.log\n");
        $builder->createStubFile('php-forge/new-provider', '.gitignore', "*.log\n");
        $builder->createProjectFile('.gitignore', "*.log\n");

        self::scaffold(
            $builder,
            ['php-forge/old-provider'],
            [
                self::provider('php-forge/old-provider',
                $manifest)
            ],
        );

        Assert::same(
            self::lockEntry($builder, '.gitignore')['provider'] ?? null,
            'php-forge/old-provider',
            "Initial owner must be 'php-forge/old-provider'.",
        );

        self::scaffold(
            $builder,
            ['php-forge/new-provider'],
            [
                self::provider('php-forge/new-provider',
                $manifest),
            ],
        );

        Assert::same(
            self::lockEntry($builder, '.gitignore')['provider'] ?? null,
            'php-forge/new-provider',
            "A skipped append must move ownership to 'php-forge/new-provider'.",
        );
    }

    /**
     * Ensures a new provider line lands under its comment header on a partial run and both hashes follow.
     */
    public function appendNewProviderLinePropagatesOnPartialRun(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $provider = self::provider(
            'php-forge/test',
            ['copy' => ['.gitignore'], 'modes' => ['.gitignore' => 'append']],
        );

        $path = "{$builder->getProjectRoot()}/.gitignore";

        $builder->createStubFile('php-forge/test', '.gitignore', "# logs\n*.log\n");
        $builder->createProjectFile('.gitignore', "vendor/\n");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        $builder->createStubFile('php-forge/test', '.gitignore', "# logs\n*.log\n*.cache\n");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        FileAssert::contentSame(
            $path,
            "vendor/\n\n# logs\n*.log\n*.cache\n",
            'New entry must land under its header.',
        );
        Assert::same(
            array_intersect_key(self::lockEntry($builder, '.gitignore'), ['hash' => true, 'stub-hash' => true]),
            ['hash' => Hasher::hash($path), 'stub-hash' => self::stubHash($builder, '.gitignore')],
            'Lock hash must follow the rewritten file and stub hash the bumped stub.',
        );
    }

    /**
     * Ensures a destination that the applier rejects is reported per file without aborting the run.
     */
    public function applierExceptionIsReportedWithoutAbortingTheRun(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $io = new BufferIO();

        $builder->createStubFile('php-forge/test', 'app.php', 'content');

        self::scaffolder($builder, self::config(['safe/other']), $io)->scaffold(
            self::config(['php-forge/test']),
            [self::provider('php-forge/test', ['copy' => ['app.php']])],
            $builder->getProjectRoot(),
            $builder->getVendorDir(),
        );

        Assert::same(
            $io->getOutput(),
            ConsoleMessage::SCAFFOLD_APPLY_FAILED->getMessage(
                'app.php',
                Message::PACKAGE_NOT_ALLOWED->getMessage('php-forge/test'),
            ) . PHP_EOL,
            'The rejection must be reported.',
        );
        FileAssert::fileDoesNotExist(
            "{$builder->getProjectRoot()}/app.php",
            'Nothing may be written.',
        );
    }

    /**
     * Ensures a destination that is a symlink escaping the root is rejected without creating the target.
     */
    public function danglingSymlinkDestinationIsNotWrittenOutsideRoot(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('Dangling symlink resolution is only verified on POSIX.');
        }

        $builder = new FakeProjectBuilder($this->tempDir);
        $io = new BufferIO();

        $outside = "{$this->tempDir}/outside";

        mkdir($outside);

        $builder->createStubFile('php-forge/test', 'rep.txt', 'provider bytes');

        symlink("{$outside}/OUTSIDE_TARGET.txt", "{$builder->getProjectRoot()}/rep.txt");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [self::provider('php-forge/test', ['copy' => ['rep.txt']])],
            $io,
        );

        FileAssert::fileDoesNotExist(
            "{$outside}/OUTSIDE_TARGET.txt",
            'No file may be created outside the root.',
        );
        Assert::same(
            $io->getOutput(),
            ConsoleMessage::SCAFFOLD_APPLY_FAILED->getMessage(
                'rep.txt',
                Message::PATH_SYMLINK_ESCAPE->getMessage('Destination', 'rep.txt'),
            ) . PHP_EOL,
            'The rejection must be reported.',
        );
    }

    /**
     * Ensures a deleted append file is written again on the next run.
     */
    public function deletedAppendFileIsRecreatedOnPartialRun(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $provider = self::provider(
            'php-forge/test',
            [
                'copy' => ['.gitignore'],
                'modes' => ['.gitignore' => 'append'],
            ],
        );

        $builder->createStubFile('php-forge/test', '.gitignore', "*.log\n");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        unlink("{$builder->getProjectRoot()}/.gitignore");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/.gitignore",
            "*.log\n",
            'File must be back.',
        );
    }

    /**
     * Ensures ejected entries of every mode keep the user content and are never rehashed.
     */
    public function ejectedEntriesAreNeverTouched(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $root = $builder->getProjectRoot();

        $lock = new LockFile($root);

        $provider = self::provider(
            'php-forge/test',
            [
                'copy' => ['replace.txt', '.gitignore', 'prepend.txt'],
                'modes' => ['.gitignore' => 'append', 'prepend.txt' => 'prepend'],
            ],
        );

        $edits = [
            'replace.txt' => "mine\n",
            '.gitignore' => "vendor/\n",
            'prepend.txt' => "code\n",
        ];

        $builder->createStubFile('php-forge/test', 'replace.txt', "stub\n");
        $builder->createStubFile('php-forge/test', '.gitignore', "*.log\n");
        $builder->createStubFile('php-forge/test', 'prepend.txt', "// header\n");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        $data = $lock->read();

        foreach ($edits as $destination => $content) {
            $builder->createProjectFile($destination, $content);

            $data['files'][$destination]['ejected'] = true;
        }

        $lock->write($data);

        $lockBytes = file_get_contents($lock->getPath());

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        Assert::same(
            [
                'replace.txt' => file_get_contents("{$root}/replace.txt"),
                '.gitignore' => file_get_contents("{$root}/.gitignore"),
                'prepend.txt' => file_get_contents("{$root}/prepend.txt"),
            ],
            $edits,
            'Ejected files must keep the user content.',
        );
        FileAssert::contentSame(
            $lock->getPath(),
            (string) $lockBytes,
            'Ejected entries must never be rehashed.',
        );
    }

    /**
     * Ensures the first run records the provider version and its absolute path outside the project root.
     */
    public function firstScaffoldPersistsProviderPathInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('php-forge/test', 'app.php', 'a');

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [
                self::provider('php-forge/test',
                ['copy' => ['app.php']]),
            ],
        );

        Assert::same(
            (new LockFile($builder->getProjectRoot()))->read()['providers'],
            [
                'php-forge/test' => [
                    'version' => '2.0.0',
                    'path' => str_replace('\\', '/', "{$builder->getVendorDir()}/php-forge/test"),
                ],
            ],
            'Provider entry must record the version and the absolute path.',
        );
    }

    /**
     * Ensures an install path inside the project root is recorded relative to it.
     */
    public function firstScaffoldRecordsRelativePathWhenProviderLivesInsideProject(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $installPath = "{$builder->getProjectRoot()}/vendor/php-forge/test";

        mkdir($installPath, 0o777, recursive: true);
        file_put_contents(
            "{$installPath}/app.php",
            'a',
        );

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [self::provider('php-forge/test', ['copy' => ['app.php']])],
            installPaths: ['php-forge/test' => $installPath],
        );

        Assert::same(
            (new LockFile($builder->getProjectRoot()))->read()['providers']['php-forge/test'] ?? null,
            ['version' => '2.0.0', 'path' => 'vendor/php-forge/test'],
            "Path must be stored relative to the project root as 'vendor/php-forge/test'.",
        );
    }

    /**
     * Ensures the provider listed last in `allowed-packages` wins a shared destination, whatever the install order.
     *
     * @param list<string> $allowed Root `allowed-packages`.
     * @param string $winner Provider expected to write the destination.
     * @param string $loser Provider expected to be overridden.
     */
    #[DataProvider(ScaffolderProvider::class . '::allowedOrders')]
    public function laterAllowedProviderWinsTheSharedDestination(array $allowed, string $winner, string $loser): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $io = new BufferIO();

        $builder->createStubFile('php-forge/base', 'app.php', 'php-forge/base');
        $builder->createStubFile('php-forge/override', 'app.php', 'php-forge/override');

        self::scaffold(
            $builder,
            $allowed,
            [
                self::provider('php-forge/base', ['copy' => ['app.php']]),
                self::provider('php-forge/override', ['copy' => ['app.php']]),
            ],
            $io,
        );

        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/app.php",
            $winner,
            "Provider '{$winner}' must win.",
        );
        Assert::same(
            array_column((new LockFile($builder->getProjectRoot()))->read()['files'], 'provider'),
            [$winner],
            "Lock must hold one entry, attributed to '{$winner}'.",
        );
        Assert::same(
            $io->getOutput(),
            ConsoleMessage::SCAFFOLD_OVERRIDE->getMessage('app.php', $winner, $loser) . PHP_EOL,
            'The collision must be reported once, naming both providers.',
        );
    }

    /**
     * Ensures the lock file is a reserved destination that a provider cannot overwrite.
     */
    public function lockFileDestinationIsRejected(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $io = new BufferIO();

        $builder->createStubFile('php-forge/test', 'evil.json', '{"providers": [], "files": []}');

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [self::provider('php-forge/test', ['copy' => [['from' => 'evil.json', 'to' => 'scaffold-lock.json']]])],
            $io,
        );

        Assert::same(
            $io->getOutput(),
            ConsoleMessage::SCAFFOLD_APPLY_FAILED->getMessage(
                'scaffold-lock.json',
                Message::PATH_RESERVED->getMessage('scaffold-lock.json'),
            ) . PHP_EOL,
            'The reserved destination must be rejected.',
        );
        Assert::same(
            (new LockFile($builder->getProjectRoot()))->read(),
            [
                'providers' => [
                    'php-forge/test' => [
                        'version' => '2.0.0',
                        'path' => str_replace('\\', '/', "{$builder->getVendorDir()}/php-forge/test"),
                    ],
                ],
                'files' => [],
            ],
            'The lock file must be written by the plugin, not copied from the provider.',
        );
    }

    /**
     * Ensures a `merge` override of the manifest applies and is recorded in the lock entry.
     */
    public function manifestMergeOverrideIsRecordedInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('php-forge/test', '.gitignore', "# b\nx\n");
        $builder->createProjectFile('.gitignore', "# a\nx\n");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [
                self::provider(
                    'php-forge/test',
                    [
                        'copy' => ['.gitignore'],
                        'modes' => ['.gitignore' => 'append'],
                        'merge' => ['.gitignore' => 'text'],
                    ],
                ),
            ],
        );

        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/.gitignore",
            "# a\nx\n\n# b\n",
            "Manifest 'text' strategy must apply.",
        );
        Assert::same(
            self::lockEntry($builder, '.gitignore')['merge'] ?? null,
            'text',
            'Override must be recorded.',
        );
    }

    /**
     * Ensures a metapackage provider, which has no install path, is skipped with a notice.
     */
    public function metapackageProviderIsSkippedWithANotice(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $io = new BufferIO();

        $provider = self::provider(
            'php-forge/meta',
            ['copy' => ['app.php']],
        );

        $builder->createStubFile('php-forge/meta', 'app.php', 'meta');
        $provider->setType('metapackage');

        self::scaffold(
            $builder,
            ['php-forge/meta'],
            [$provider],
            $io,
        );

        Assert::same(
            $io->getOutput(),
            ConsoleMessage::SCAFFOLD_PROVIDER_INSTALL_PATH_MISSING->getMessage('php-forge/meta') . PHP_EOL,
            'The skipped metapackage must be reported.',
        );
        FileAssert::fileDoesNotExist(
            "{$builder->getProjectRoot()}/app.php",
            'Nothing may be written.',
        );
    }

    /**
     * Ensures an allowed but uninstalled provider is reported and the later providers still run.
     */
    public function missingProviderPackageDoesNotHaltLaterProviders(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $io = new BufferIO();

        $builder->createStubFile('php-forge/good', 'app.php', 'good-content');

        self::scaffold(
            $builder,
            ['php-forge/missing', 'php-forge/good'],
            [self::provider('php-forge/good', ['copy' => ['app.php']])],
            $io,
        );

        Assert::same(
            $io->getOutput(),
            ConsoleMessage::SCAFFOLD_PROVIDER_NOT_INSTALLED->getMessage('php-forge/missing') . PHP_EOL,
            'The uninstalled provider must be reported.',
        );
        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/app.php",
            'good-content',
            'Later provider must run.',
        );
    }

    /**
     * Ensures a non-string `allowed-packages` entry is skipped and the later string entries still run.
     */
    public function nonStringAllowedEntryIsSkippedWithoutHaltingLaterProviders(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $config = ProjectConfig::fromExtra(['scaffold' => ['allowed-packages' => [42, 'php-forge/good']]]);

        $builder->createStubFile('php-forge/good', 'app.php', 'good-content');

        self::scaffolder($builder, $config, new BufferIO())->scaffold(
            $config,
            [self::provider('php-forge/good', ['copy' => ['app.php']])],
            $builder->getProjectRoot(),
            $builder->getVendorDir(),
        );

        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/app.php",
            'good-content',
            "'php-forge/good' must run.",
        );
    }

    /**
     * Pins the path prefix comparison, including the case-insensitive branch that only Windows paths reach.
     */
    #[DataProvider(ScaffolderProvider::class . '::prefixMatches')]
    public function prefixMatchesHonorsCaseInsensitiveFlag(
        string $haystack,
        string $needle,
        bool $caseInsensitive,
        bool $expected,
    ): void {
        Assert::same(
            (new ReflectionMethod(ScaffoldRunner::class, 'prefixMatches'))
                ->invoke(null, $haystack, $needle, $caseInsensitive),
            $expected,
            'Prefix comparison must honor the case flag.',
        );
    }

    /**
     * Ensures a preserve run keeps the recorded hash of an already tracked file that the user changed.
     */
    public function preserveDoesNotOverwriteAlreadyTrackedLockEntry(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $provider = self::provider(
            'php-forge/test',
            ['copy' => ['config.php'], 'modes' => ['config.php' => 'preserve']],
        );

        $builder->createStubFile('php-forge/test', 'config.php', 'stub');
        $builder->createProjectFile('config.php', 'user-content');

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        file_put_contents(
            "{$builder->getProjectRoot()}/config.php",
            'user-modified',
        );

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        Assert::same(
            self::lockEntry($builder, 'config.php')['hash'] ?? null,
            'sha256:' . hash('sha256', 'user-content'),
            'Recorded hash must stay the one of the first run.',
        );
    }

    /**
     * Ensures preserve mode keeps an untracked existing file and records its hash in a new lock.
     */
    public function preserveRecordsExistingUntrackedFileInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $path = "{$builder->getProjectRoot()}/config/params.php";

        $builder->createStubFile('php-forge/test', 'config/params.php', 'stub content');
        $builder->createProjectFile('config/params.php', 'user content');

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [
                self::provider(
                    'php-forge/test',
                    ['copy' => ['config/params.php'], 'modes' => ['config/params.php' => 'preserve']],
                ),
            ],
        );

        FileAssert::contentSame(
            $path,
            'user content',
            'Preserve file must not be overwritten.',
        );
        Assert::same(
            self::lockEntry($builder, 'config/params.php')['hash'] ?? null,
            Hasher::hash($path),
            'Recorded hash must match the untouched user content.',
        );
    }

    /**
     * Ensures a provider that yields no file mappings is reported and no lock is written.
     *
     * @param array<string, mixed> $extra Provider `extra` block.
     * @param array<string, string> $stubs Provider files keyed by path.
     */
    #[DataProvider(ScaffolderProvider::class . '::providersWithoutMappings')]
    public function providerWithoutMappingsWritesNoticeAndNoLock(array $extra, array $stubs): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $io = new BufferIO();
        $provider = new CompletePackage('php-forge/empty', '2.0.0.0', '2.0.0');

        foreach ($stubs as $path => $content) {
            $builder->createStubFile('php-forge/empty', $path, $content);
        }

        $provider->setExtra($extra);

        self::scaffold(
            $builder,
            ['php-forge/empty'],
            [$provider],
            $io,
        );

        Assert::same(
            $io->getOutput(),
            ConsoleMessage::SCAFFOLD_PROVIDER_MAPPINGS_EMPTY->getMessage('php-forge/empty') . PHP_EOL,
            'A provider yielding zero mappings must be reported.',
        );
        FileAssert::fileDoesNotExist(
            "{$builder->getProjectRoot()}/scaffold-lock.json",
            'No lock may be written.',
        );
    }

    /**
     * Ensures replace mode writes a missing file and records its hash, provider, source, mode and stub hash.
     */
    public function replaceFileIsWrittenAndRecordedWithItsHash(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $hash = 'sha256:' . hash('sha256', 'server {}');

        $builder->createStubFile('php-forge/app-base-scaffold', 'config/nginx.conf', 'server {}');

        self::scaffold(
            $builder,
            ['php-forge/app-base-scaffold'],
            [self::provider('php-forge/app-base-scaffold', ['copy' => ['config/nginx.conf']])],
        );

        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/config/nginx.conf",
            'server {}',
            'File must be written.',
        );
        Assert::same(
            self::lockEntry($builder, 'config/nginx.conf'),
            [
                'hash' => $hash,
                'provider' => 'php-forge/app-base-scaffold',
                'source' => 'config/nginx.conf',
                'mode' => 'replace',
                'stub-hash' => $hash,
            ],
            'Lock entry must record the written file.',
        );
    }

    /**
     * Ensures replace mode overwrites a differing untracked file and reports it.
     */
    public function replaceOverDifferingUntrackedFileWarns(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $io = new BufferIO();

        $builder->createStubFile('php-forge/test', '.styleci.yml', "preset: psr12\n");
        $builder->createProjectFile('.styleci.yml', "preset: laravel\n");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [self::provider('php-forge/test', ['copy' => ['.styleci.yml']])],
            $io,
        );

        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/.styleci.yml",
            "preset: psr12\n",
            'Provider must win on adoption.',
        );
        Assert::same(
            $io->getOutput(),
            ConsoleMessage::SCAFFOLD_PREFIXED->getMessage(
                ConsoleMessage::SCAFFOLD_UNTRACKED_OVERWRITTEN->getMessage('.styleci.yml'),
            ) . PHP_EOL,
            'Overwrite must be reported.',
        );
    }

    /**
     * Ensures replace mode rewrites a tracked destination and its lock hash when the stub changes.
     */
    public function replaceOverwritesLockEntryForPreviouslyTrackedDestination(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $provider = self::provider(
            'php-forge/test',
            ['copy' => ['app.php']],
        );

        $builder->createStubFile('php-forge/test', 'app.php', 'v1');

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        $builder->createStubFile('php-forge/test', 'app.php', 'v2');

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/app.php",
            'v2',
            'File must hold the new stub.',
        );
        Assert::same(
            self::lockEntry($builder, 'app.php')['hash'] ?? null,
            'sha256:' . hash('sha256', 'v2'),
            'Lock hash must follow the rewritten file.',
        );
    }

    /**
     * Ensures a root package without allowed providers writes only its notice and touches nothing.
     *
     * @param array<string, mixed> $extra Root package `extra` block.
     * @param string $notice Expected notice.
     */
    #[DataProvider(ScaffolderProvider::class . '::rootsWithoutProviders')]
    public function rootWithoutAllowedProvidersWritesOnlyTheNotice(array $extra, string $notice): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $io = new BufferIO();
        $config = ProjectConfig::fromExtra($extra);

        // A lock tracking a file no provider ships would yield an orphan report if the run went on.
        $lock = '{"providers": {}, "files": {"old.txt": {"hash": "sha256:x", "provider": "php-forge/gone", '
            . '"source": "old.txt", "mode": "replace"}}}';

        $builder->createStubFile('php-forge/test', 'app.php', 'a');
        $builder->createProjectFile('scaffold-lock.json', $lock);

        self::scaffolder($builder, $config, $io)->scaffold(
            $config,
            [self::provider('php-forge/test', ['copy' => ['app.php']])],
            $builder->getProjectRoot(),
            $builder->getVendorDir(),
        );

        Assert::same(
            $io->getOutput(),
            $notice . PHP_EOL,
            'Only the notice may be written.',
        );
        FileAssert::fileDoesNotExist(
            "{$builder->getProjectRoot()}/app.php",
            'No file may be written.',
        );
        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/scaffold-lock.json",
            $lock,
            'The lock must stay unchanged.',
        );
    }

    /**
     * Ensures a provider path change alone rewrites the lock with the fresh version and path.
     */
    public function scaffoldPersistsUpdatedProviderPathWhenOnlyProviderChanged(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('php-forge/test', 'config.php', 'stub');
        $builder->createProjectFile('config.php', 'user-content');

        (new LockFile($builder->getProjectRoot()))->write(
            [
                'providers' => ['php-forge/test' => ['path' => '/obsolete/path']],
                'files' => [
                    'config.php' => [
                        'hash' => 'sha256:' . hash('sha256', 'user-content'),
                        'provider' => 'php-forge/test',
                        'source' => 'config.php',
                        'mode' => 'preserve',
                    ],
                ],
            ],
        );

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [self::provider('php-forge/test', ['copy' => ['config.php'], 'modes' => ['config.php' => 'preserve']])],
        );

        Assert::same(
            (new LockFile($builder->getProjectRoot()))->read()['providers'],
            [
                'php-forge/test' => [
                    'version' => '2.0.0',
                    'path' => str_replace('\\', '/', "{$builder->getVendorDir()}/php-forge/test"),
                ],
            ],
            'The fresh version and path must be persisted.',
        );
    }

    /**
     * Ensures a second run leaves a file that already holds the provider block unchanged.
     *
     * @param string $mode Merge mode of the file.
     * @param string $stub Provider content.
     * @param string $existing Project content before the first run.
     * @param string $expected Content after the first run.
     */
    #[DataProvider(ScaffolderProvider::class . '::mergedFiles')]
    public function secondRunLeavesAMergedFileUnchanged(
        string $mode,
        string $stub,
        string $existing,
        string $expected,
    ): void {
        $builder = new FakeProjectBuilder($this->tempDir);

        $provider = self::provider(
            'php-forge/test',
            [
                'copy' => ['file.txt'],
                'modes' => ['file.txt' => $mode],
            ],
        );

        $path = "{$builder->getProjectRoot()}/file.txt";

        $builder->createStubFile('php-forge/test', 'file.txt', $stub);
        $builder->createProjectFile('file.txt', $existing);

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        $first = file_get_contents($path);

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        Assert::same(
            [$first, file_get_contents($path)],
            [$expected, $expected],
            "The '{$mode}' block must be added once.",
        );
    }

    /**
     * Ensures the stub hash is recorded for every mode, adoption is flagged, and a user-modified skip keeps it.
     */
    public function stubHashIsRecordedPerMode(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $modes = [
            '.gitignore' => 'append',
            'existing.txt' => 'preserve',
            'prepend.txt' => 'prepend',
            'preserve.txt' => 'preserve',
            'replace.txt' => 'replace',
        ];

        $provider = self::provider(
            'php-forge/test',
            [
                'copy' => array_keys($modes),
                'modes' => $modes,
            ],
        );

        foreach (array_keys($modes) as $file) {
            $builder->createStubFile('php-forge/test', $file, "{$file} v1\n");
        }

        $builder->createProjectFile('existing.txt', "mine\n");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        $files = (new LockFile($builder->getProjectRoot()))->read()['files'];

        Assert::same(
            [
                array_map(static fn(array $entry): mixed => $entry['stub-hash'] ?? null, $files),
                array_keys(array_filter($files, static fn(array $entry): bool => isset($entry['adopted']))),
            ],
            [
                [
                    '.gitignore' => self::stubHash($builder, '.gitignore'),
                    'existing.txt' => self::stubHash($builder, 'existing.txt'),
                    'prepend.txt' => self::stubHash($builder, 'prepend.txt'),
                    'preserve.txt' => self::stubHash($builder, 'preserve.txt'),
                    'replace.txt' => self::stubHash($builder, 'replace.txt'),
                ],
                ['existing.txt'],
            ],
            "Every file must record its stub hash and only 'existing.txt' must be adopted.",
        );

        $v1Hash = self::stubHash(
            $builder,
            'replace.txt',
        );

        $builder->createProjectFile('replace.txt', "mine\n");
        $builder->createStubFile('php-forge/test', 'replace.txt', "replace.txt v2\n");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        Assert::same(
            self::lockEntry($builder, 'replace.txt')['stub-hash'] ?? null,
            $v1Hash,
            'User-modified replace skip must keep the stub hash of the last apply.',
        );
    }

    /**
     * Ensures a file after a skipped append entry is still applied on a partial run.
     */
    public function subsequentFileIsAppliedAfterASkippedEntry(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $provider = self::provider(
            'php-forge/test',
            [
                'copy' => ['append.txt', 'fresh.txt'],
                'modes' => ['append.txt' => 'append'],
            ],
        );

        $builder->createStubFile('php-forge/test', 'append.txt', 'appended');
        $builder->createStubFile('php-forge/test', 'fresh.txt', 'fresh content');
        $builder->createProjectFile('append.txt', 'existing');

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        unlink("{$builder->getProjectRoot()}/fresh.txt");

        self::scaffold(
            $builder,
            ['php-forge/test'],
            [$provider],
        );

        FileAssert::contentSame(
            "{$builder->getProjectRoot()}/fresh.txt",
            'fresh content',
            'The loop must continue after the skipped entry.',
        );
    }

    /**
     * Ensures a manifest failure is written and counted as an error before the exception.
     */
    public function throwScaffoldExceptionAfterReportingManifestFailure(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $io = new BufferIO();
        $summary = new ScaffoldSummary();

        $scaffolder = self::scaffolder(
            $builder,
            self::config(['php-forge/bad']),
            $io,
            $summary,
        );

        $expected = Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage(
            'php-forge/bad',
            Message::MANIFEST_REFERENCE_NOT_RELATIVE->getMessage('php-forge/bad'),
        );

        $exception = self::catchScaffoldException(
            static fn() => $scaffolder->scaffold(
                self::config(['php-forge/bad']),
                [self::provider('php-forge/bad', ['manifest' => '../escape.json'])],
                $builder->getProjectRoot(),
                $builder->getVendorDir(),
            ),
        );

        Assert::same(
            [$exception?->getMessage(), $io->getOutput(), $summary->errors],
            [$expected, $expected . PHP_EOL, 1],
            "Failure must be thrown, written once and counted as '1' error.",
        );
    }

    /**
     * Ensures a manifest failure of any allowed provider aborts the run before any file or lock change.
     */
    public function throwScaffoldExceptionWhenManifestFailsBeforeAnyFileIsApplied(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('php-forge/good', 'app.php', 'good');
        $builder->createProjectFile(
            'scaffold-lock.json',
            '{"providers": {"php-forge/bad": {"version": "1.0.0", "path": "../vendor/php-forge/bad"}}, "files": {}}',
        );

        $exception = self::catchScaffoldException(
            static fn() => self::scaffold(
                $builder,
                ['php-forge/good', 'php-forge/bad'],
                [
                    self::provider('php-forge/bad', ['manifest' => 'missing.json']),
                    self::provider('php-forge/good', ['copy' => ['app.php']]),
                ],
            ),
        );

        Assert::same(
            $exception?->getMessage(),
            Message::SCAFFOLD_MANIFEST_LOAD_FAILED->getMessage(
                'php-forge/bad',
                Message::MANIFEST_PROVIDER_FILE_NOT_FOUND->getMessage(
                    'php-forge/bad',
                    'manifest file',
                    "{$builder->getVendorDir()}/php-forge/bad/missing.json",
                ),
            ),
            'Exception must name the provider.',
        );
        FileAssert::fileDoesNotExist(
            "{$builder->getProjectRoot()}/app.php",
            'No provider may be applied.',
        );
        Assert::same(
            (new LockFile($builder->getProjectRoot()))->read()['providers'],
            ['php-forge/bad' => ['version' => '1.0.0', 'path' => '../vendor/php-forge/bad']],
            'Lock must stay untouched.',
        );
    }

    /**
     * Runs `$run` and returns the {@see ScaffoldException} it throws, or `null`.
     *
     * @param Closure(): void $run Code expected to throw.
     */
    private static function catchScaffoldException(Closure $run): ScaffoldException|null
    {
        try {
            $run();
        } catch (ScaffoldException $e) {
            return $e;
        }

        return null;
    }

    /**
     * Returns the configuration of a root package whose `allowed-packages` is `$allowed`.
     *
     * @param list<string> $allowed Allowed provider names.
     */
    private static function config(array $allowed): ProjectConfig
    {
        return ProjectConfig::fromExtra(['scaffold' => ['allowed-packages' => $allowed]]);
    }

    /**
     * Returns the lock entry of `$destination` in the project of `$builder`, or an empty array.
     *
     * @return array<array-key, mixed>
     */
    private static function lockEntry(FakeProjectBuilder $builder, string $destination): array
    {
        return (new LockFile($builder->getProjectRoot()))->read()['files'][$destination] ?? [];
    }

    /**
     * Returns the provider package `$name` at version `2.0.0` with `$manifest` as its `extra.scaffold`.
     *
     * @param array<string, mixed> $manifest Raw `extra.scaffold` of the provider.
     */
    private static function provider(string $name, array $manifest): CompletePackage
    {
        $package = new CompletePackage($name, '2.0.0.0', '2.0.0');

        $package->setExtra(['scaffold' => $manifest]);

        return $package;
    }

    /**
     * Scaffolds `$packages` into the project of `$builder` for a root package that allows `$allowed`.
     *
     * @param list<string> $allowed Allowed provider names.
     * @param list<PackageInterface> $packages Installed packages.
     * @param BufferIO $io IO of the run.
     * @param array<string, string> $installPaths Install paths keyed by package name.
     */
    private static function scaffold(
        FakeProjectBuilder $builder,
        array $allowed,
        array $packages,
        BufferIO $io = new BufferIO(),
        array $installPaths = [],
    ): void {
        $config = self::config($allowed);

        self::scaffolder($builder, $config, $io)->scaffold(
            $config,
            $packages,
            $builder->getProjectRoot(),
            $builder->getVendorDir(),
            $installPaths,
        );
    }

    /**
     * Returns a scaffolder for the project of `$builder` whose applier authorizes the providers of `$config`.
     */
    private static function scaffolder(
        FakeProjectBuilder $builder,
        ProjectConfig $config,
        BufferIO $io,
        ScaffoldSummary $summary = new ScaffoldSummary(),
    ): Scaffolder {
        return new Scaffolder(
            new ManifestLoader(new ManifestSchema(), new ManifestExpander()),
            new FileApplier($config, new PathValidator(), new ComposerIoWriter($io)),
            new LockFile($builder->getProjectRoot()),
            new ComposerIoWriter($io),
            $summary,
        );
    }

    /**
     * Returns the `sha256:` hash of a stub file of `php-forge/test`.
     */
    private static function stubHash(FakeProjectBuilder $builder, string $relative): string
    {
        return Hasher::hash("{$builder->getVendorDir()}/php-forge/test/{$relative}");
    }
}
