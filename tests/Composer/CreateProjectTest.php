<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Composer;

use PHPForge\Scaffold\Console\Message;
use PHPForge\Scaffold\Tests\Support\{FileAssert, RealComposer};
use Testo\Assert;
use Testo\Test;

use function copy;
use function dirname;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function rmdir;
use function substr_count;

/**
 * Functional tests that run `composer create-project` of a template without `composer.lock` with the real Composer
 * binary.
 */
#[Test]
final class CreateProjectTest extends AbstractComposerScenario
{
    /**
     * Ensures `post-update-cmd` and `post-create-project-cmd` of one `create-project` run scaffold the template once.
     */
    public function templateWithoutLockIsScaffoldedOnce(): void
    {
        RealComposer::requireCommand();

        $template = "{$this->workspace}/template";

        $fixture = dirname(__DIR__) . '/Fixtures/composer/template';

        mkdir($template);
        copy("{$fixture}/prepend.txt", "{$template}/prepend.txt");
        copy("{$fixture}/gitignore", "{$template}/.gitignore");
        file_put_contents(
            "{$template}/composer.json",
            json_encode(
                [
                    'name' => 'fixture/template',
                    'type' => 'project',
                    'repositories' => $this->repositories(),
                    'require' => [...RealComposer::requirements(), 'fixture/provider' => '*'],
                    'config' => ['allow-plugins' => ['php-forge/scaffold' => true]],
                    'extra' => ['scaffold' => ['allowed-packages' => ['fixture/provider']]],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
        rmdir($this->project);

        $result = $this->composer(
            [
                'create-project',
                'fixture/template',
                'project',
                '--repository=' . json_encode(
                    [
                        'type' => 'path',
                        'url' => $template,
                        'options' => ['symlink' => false, 'versions' => ['fixture/template' => '1.0.0']],
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            ],
            $this->workspace,
        );

        Assert::same(
            $result->status(),
            '0',
            "Create-project must exit with '0'.",
        );
        FileAssert::contentSame(
            $this->path('prepend.txt'),
            "HEADER\nbody\n",
            'Prepend block must be added once.',
        );
        FileAssert::contentSame(
            $this->path('.gitignore'),
            "/mine\n\n# Dependencies\n/vendor\n",
            'Template entries must stay first and the provider block must be merged once.',
        );
        Assert::same(
            substr_count(
                $result->stdout,
                Message::SCAFFOLD_PREFIXED->getMessage(
                    Message::FRAGMENT_COUNT_WRITTEN->getMessage(6, Message::FRAGMENT_FILES->getMessage()),
                ),
            ),
            1,
            "The workflow must run exactly '1' time.",
        );
        FileAssert::fileExists(
            $this->path('scaffold-lock.json'),
            'Lock must be written.',
        );
    }
}
