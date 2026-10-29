<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Functional;

use Composer\Script\ScriptEvents;
use PHPForge\Scaffold\EventSubscriber;
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Tests\Provider\PostCreateProjectProvider;
use PHPForge\Scaffold\Tests\Support\{ComposerEventHarness, FileAssert, TempDirectoryTrait};
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

use function array_keys;
use function file_get_contents;
use function hash;
use function unlink;

/**
 * Functional tests for the `post-create-project-cmd` flow of {@see EventSubscriber} and its interaction with
 * `post-install-cmd` and `post-update-cmd`.
 *
 * Composer fires `post-create-project-cmd` after one of the other two events during `composer create-project`, so the
 * plugin must apply the scaffold once and never duplicate append or prepend blocks.
 *
 * {@see PostCreateProjectProvider} for test case data providers.
 */
#[Test]
final class PostCreateProjectTest
{
    use ComposerEventHarness;
    use TempDirectoryTrait;

    /**
     * Ensures each scaffold event merges the missing entry into a locked append file once, also on a second run.
     */
    #[DataProvider(PostCreateProjectProvider::class . '::scaffoldEvents')]
    public function lockedAppendFileReceivesTheMissingEntryOnce(string $event): void
    {
        $builder = $this->project();

        $manifest = ['copy' => ['.gitignore'], 'modes' => ['.gitignore' => 'append']];

        $path = "{$builder->getProjectRoot()}/.gitignore";

        $builder->createStubFile('demo/scaffold', '.gitignore', "/runtime/\n");
        $builder->createProjectFile('.gitignore', "existing\n");

        (new LockFile($builder->getProjectRoot()))->write(
            [
                'providers' => [
                    'demo/scaffold' => ['version' => '1.0.0', 'path' => "{$builder->getVendorDir()}/demo/scaffold"],
                ],
                'files' => [
                    '.gitignore' => [
                        'hash' => 'sha256:' . hash('sha256', "existing\n"),
                        'provider' => 'demo/scaffold',
                        'source' => '.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );

        $this->dispatch($builder, $manifest, $event);

        FileAssert::contentSame(
            $path,
            "existing\n\n/runtime/\n",
            'Locked append file must receive the missing entry.',
        );

        $this->dispatch($builder, $manifest, $event);

        FileAssert::contentSame(
            $path,
            "existing\n\n/runtime/\n",
            'Second run must not duplicate the entry.',
        );
    }

    /**
     * Ensures `post-create-project-cmd` does nothing after `post-install-cmd` or `post-update-cmd` in one process.
     */
    #[DataProvider(PostCreateProjectProvider::class . '::installEvents')]
    public function postCreateProjectDoesNothingAfterAnInstallRun(string $event): void
    {
        $builder = $this->project();

        $root = $builder->getProjectRoot();

        $manifest = [
            'copy' => ['.gitignore', 'config/params.php', 'src/bootstrap.php'],
            'modes' => ['.gitignore' => 'append', 'src/bootstrap.php' => 'prepend'],
        ];

        $builder->createStubFile('demo/scaffold', '.gitignore', "/runtime/\n");
        $builder->createStubFile('demo/scaffold', 'config/params.php', "<?php return [];\n");
        $builder->createStubFile('demo/scaffold', 'src/bootstrap.php', "// PROVIDER HEADER\n");
        $builder->createProjectFile('src/bootstrap.php', "// user code\n");

        $this->dispatch($builder, $manifest, $event, '2.5.0');

        unlink("{$root}/config/params.php");

        Assert::same(
            $this->dispatch($builder, $manifest, ScriptEvents::POST_CREATE_PROJECT_CMD, '2.5.0'),
            '',
            'The skipped run must write nothing.',
        );
        Assert::same(
            [
                '.gitignore' => file_get_contents("{$root}/.gitignore"),
                'src/bootstrap.php' => file_get_contents("{$root}/src/bootstrap.php"),
            ],
            [
                '.gitignore' => "/runtime/\n",
                'src/bootstrap.php' => "// PROVIDER HEADER\n// user code\n",
            ],
            'Append and prepend blocks must appear once.',
        );
        FileAssert::fileDoesNotExist(
            "{$root}/config/params.php",
            'The skipped run must not restore a deleted file.',
        );

        $lock = (new LockFile($root))->read();

        Assert::same(
            [$lock['providers'], array_keys($lock['files'])],
            [
                ['demo/scaffold' => ['version' => '2.5.0', 'path' => "{$builder->getVendorDir()}/demo/scaffold"]],
                ['.gitignore', 'config/params.php', 'src/bootstrap.php'],
            ],
            "Lock must record provider version '2.5.0' and every file.",
        );
    }
}
