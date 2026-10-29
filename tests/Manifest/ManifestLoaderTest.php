<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Manifest;

use Exception;
use PHPForge\Scaffold\Exception\Message;
use PHPForge\Scaffold\Manifest\{FileMapping, FileMode, ManifestExpander, ManifestLoader, ManifestSchema};
use PHPForge\Scaffold\Tests\Provider\ManifestLoaderProvider;
use PHPForge\Scaffold\Tests\Support\TempDirectoryTrait;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\{DataProvider, DataSet};
use Testo\Expect;
use Testo\Test;

use function dirname;
use function file_put_contents;

/**
 * Unit tests for {@see ManifestLoader} reading the inline or external provider manifest, from the `extra` section or
 * from the provider `composer.json`, and expanding it into file mappings.
 *
 * {@see ManifestLoaderProvider} for test case data providers.
 */
#[Test]
#[Covers(ManifestLoader::class)]
final class ManifestLoaderTest
{
    use TempDirectoryTrait;

    /**
     * Ensures an external manifest named in `composer.json` next to consumer keys is read from the provider root.
     */
    public function expandsTheExternalManifestOfComposerJson(): void
    {
        // Shape of 'php-forge/coding-standard': a provider that is also a consumer of 'php-forge/baseline'.
        $this->seed('src/Foo.php');
        $this->seed('stubs/ecs.php');
        $this->seed('config/params.php');
        $this->seed('config/web.php');
        $this->seed(
            'composer.json',
            '{"extra": {"scaffold": {"manifest": "scaffold.json", "allowed-packages": ["php-forge/baseline"]}}}',
        );
        $this->seed(
            'scaffold.json',
            '{"copy": ["src", {"from": "stubs/ecs.php", "to": "ecs.php"}, "config"], "exclude": ["config/web.php"], '
            . '"modes": {"config/*.php": "preserve", "ecs.php": "append"}}',
        );

        $provider = 'php-forge/coding-standard';

        Assert::equals(
            $this->loader()->loadFromPath($provider, $this->tempDir),
            [
                $this->mapping('src/Foo.php', 'src/Foo.php', FileMode::Replace, $provider),
                $this->mapping('ecs.php', 'stubs/ecs.php', FileMode::Append, $provider),
                $this->mapping('config/params.php', 'config/params.php', FileMode::Preserve, $provider),
            ],
            "Every key of the external manifest must apply to the mappings of 'php-forge/coding-standard'.",
        );
    }

    /**
     * Ensures an inline manifest is expanded even when the block also carries the consumer keys.
     */
    public function expandsTheInlineManifestNextToConsumerKeys(): void
    {
        $this->seed('src/Foo.php');
        $this->seed('config/params.php');

        Assert::equals(
            $this->loader()->loadFromExtra(
                [
                    'scaffold' => [
                        'copy' => ['src', 'config'],
                        'modes' => ['config/*.php' => 'preserve'],
                        'allowed-packages' => ['php-forge/baseline'],
                        'auto' => false,
                    ],
                ],
                'pkg/example',
                $this->tempDir,
            ),
            [
                $this->mapping('src/Foo.php', 'src/Foo.php', FileMode::Replace, 'pkg/example'),
                $this->mapping('config/params.php', 'config/params.php', FileMode::Preserve, 'pkg/example'),
            ],
            'Inline manifest must expand into one mapping per file of the provider.',
        );
    }

    /**
     * Ensures a manifest reference with a colon past the drive-letter position is a relative path.
     */
    public function externalManifestPathMayHoldAColonPastTheDriveLetter(): void
    {
        $this->seed('src/Foo.php');
        $this->seed('subdir/a:b.json', '{"copy": ["src"]}');

        Assert::equals(
            $this->loader()->loadFromExtra(
                ['scaffold' => ['manifest' => 'subdir/a:b.json']],
                'pkg/example',
                $this->tempDir,
            ),
            [$this->mapping('src/Foo.php', 'src/Foo.php', FileMode::Replace, 'pkg/example')],
            "Manifest 'subdir/a:b.json' must be read.",
        );
    }

    /**
     * Ensures a provider `composer.json` without a `scaffold` object yields no mappings.
     */
    #[DataSet(['{}'], 'no extra')]
    #[DataSet(['{"extra": "text"}'], 'extra not an object')]
    #[DataSet(['{"extra": {"scaffold": "invalid"}}'], 'scaffold not an object')]
    public function loadFromPathYieldsNothingWithoutAScaffoldObject(string $composerJson): void
    {
        $this->seed('composer.json', $composerJson);

        Assert::same(
            $this->loader()->loadFromPath('p/a', $this->tempDir),
            [],
            "Package without a 'scaffold' object must yield no mappings.",
        );
    }

    /**
     * Ensures an empty, non-string, absolute or traversing manifest reference is rejected.
     */
    #[DataProvider(ManifestLoaderProvider::class . '::invalidReferences')]
    public function throwRuntimeExceptionForInvalidReference(mixed $reference, Message $message): void
    {
        Expect::exception(RuntimeException::class)
            ->withMessage(
                $message->getMessage('pkg/bad'),
            );

        $this
            ->loader()
            ->loadFromExtra(['scaffold' => ['manifest' => $reference]], 'pkg/bad', $this->tempDir);
    }

    /**
     * Ensures an external manifest body may not carry the consumer keys of the inline block.
     */
    public function throwRuntimeExceptionWhenExternalManifestDeclaresConsumerKey(): void
    {
        $this->seed('scaffold.json', '{"copy": ["src"], "allowed-packages": ["a/b"]}');

        Expect::exception(RuntimeException::class)
            ->withMessage(Message::MANIFEST_KEY_UNKNOWN->getMessage('allowed-packages', 'copy, exclude, merge, modes'));

        $this
            ->loader()
            ->loadFromExtra(['scaffold' => ['manifest' => 'scaffold.json']], 'pkg/example', $this->tempDir);
    }

    /**
     * Ensures an unreadable provider file is reported with the PHP warning.
     */
    #[DataSet(['composer.json'], 'composer.json')]
    #[DataSet(['manifest file'], 'manifest file')]
    public function throwRuntimeExceptionWhenProviderFileCannotBeRead(string $label): void
    {
        $path = $this->seedProviderFile($label, '{}');

        $this->restrictFileAccess($path, 0o000);

        Expect::exception(RuntimeException::class)
            ->withMessage(
                Message::FILESYSTEM_FAILED->getMessage(
                    Message::MANIFEST_PROVIDER_FILE_UNREADABLE->getMessage('p/a', $label, $path),
                    "file_get_contents({$path}): Failed to open stream: Permission denied",
                ),
            );

        $this
            ->loader()
            ->loadFromPath('p/a', $this->tempDir);
    }

    /**
     * Ensures a missing, non-object or invalid JSON provider file is rejected.
     *
     * @param string|null $content Content of the file, or `null` when it is missing.
     * @param class-string<Exception> $exception Expected exception class.
     * @param Message|string $message Message case rendered with the package, label and path, or the literal message.
     */
    #[DataProvider(ManifestLoaderProvider::class . '::decodeFailures')]
    public function throwWhenProviderFileCannotBeDecoded(
        string $label,
        string|null $content,
        string $exception,
        Message|string $message,
    ): void {
        $path = $this->seedProviderFile($label, $content);

        Expect::exception($exception)
            ->withMessage($message instanceof Message ? $message->getMessage('p/a', $label, $path) : $message);

        $this
            ->loader()
            ->loadFromPath('p/a', $this->tempDir);
    }

    /**
     * Returns a manifest loader with the default schema and expander.
     */
    private function loader(): ManifestLoader
    {
        return new ManifestLoader(new ManifestSchema(), new ManifestExpander());
    }

    /**
     * Returns the mapping of a file of the provider rooted at the temp directory.
     */
    private function mapping(string $destination, string $source, FileMode $mode, string $provider): FileMapping
    {
        return new FileMapping($destination, $source, $mode, $provider, $this->tempDir);
    }

    /**
     * Writes `$content` at `$relative` under the temp directory, creating intermediate directories as needed.
     */
    private function seed(string $relative, string $content = ''): void
    {
        $absolute = "{$this->tempDir}/{$relative}";

        $this->ensureTestDirectory(dirname($absolute));

        file_put_contents(
            $absolute,
            $content,
        );
    }

    /**
     * Writes the provider `composer.json`, or the external manifest it names, and returns the path of that file.
     *
     * @param string $label `composer.json`, or `manifest file` for the external manifest.
     * @param string|null $content Content of the file, or `null` to leave it missing.
     *
     * @return string Absolute path of the file.
     */
    private function seedProviderFile(string $label, string|null $content): string
    {
        $relative = $label === 'composer.json' ? 'composer.json' : 'scaffold.json';

        if ($relative === 'scaffold.json') {
            $this->seed('composer.json', '{"extra": {"scaffold": {"manifest": "scaffold.json"}}}');
        }

        if ($content !== null) {
            $this->seed($relative, $content);
        }

        return "{$this->tempDir}/{$relative}";
    }
}
