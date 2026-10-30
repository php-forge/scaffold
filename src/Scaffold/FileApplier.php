<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Scaffold;

use PHPForge\Scaffold\Config\ProjectConfig;
use PHPForge\Scaffold\Console\{Message, OutputWriter};
use PHPForge\Scaffold\Exception\Message as ExceptionMessage;
use PHPForge\Scaffold\Manifest\FileMapping;
use PHPForge\Scaffold\Scaffold\Modes\{ApplyResult, ModeInterface};
use PHPForge\Scaffold\Security\PathValidator;
use RuntimeException;

/**
 * Applies a single file mapping after checking that its provider is in `allowed-packages` and validating its source
 * and destination, reporting mode warnings through an {@see OutputWriter}.
 */
final readonly class FileApplier
{
    /**
     * Creates an applier for validated provider mappings.
     *
     * @param ProjectConfig $config Consumer settings that list the providers allowed to write files.
     * @param PathValidator $pathValidator Validates destinations and sources.
     * @param OutputWriter $out Output sink that receives mode warnings on stderr.
     */
    public function __construct(
        private ProjectConfig $config,
        private PathValidator $pathValidator,
        private OutputWriter $out,
    ) {}

    /**
     * Validates a file mapping and applies it with `$mode` against the canonical project and provider roots, writing
     * the mode warning to stderr.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param ModeInterface $mode Write mode that applies the mapping.
     * @param string|null $hashAtScaffold Hash recorded in the lock file, or `null` when the destination is untracked.
     *
     * @throws RuntimeException when the provider is not allowed, a path fails validation, or the mode cannot read,
     * write or hash a file.
     *
     * @return ApplyResult Outcome, destination hash and optional warning returned by the mode.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        ModeInterface $mode,
        string|null $hashAtScaffold,
    ): ApplyResult {
        if (!$this->config->isAllowed($mapping->providerName)) {
            throw new RuntimeException(
                ExceptionMessage::PACKAGE_NOT_ALLOWED->getMessage($mapping->providerName),
            );
        }

        $realRoot = $this->pathValidator->validateDestination($mapping->destination, $projectRoot);
        $realProviderPath = $this->pathValidator->validateSource($mapping->source, $mapping->providerPath);

        $result = $mode->apply(
            new FileMapping(
                $mapping->destination,
                $mapping->source,
                $mapping->mode,
                $mapping->providerName,
                $realProviderPath,
                $mapping->mergeStrategy,
            ),
            $realRoot,
            $hashAtScaffold,
        );

        if ($result->warning !== null) {
            $this->out->writeStderr(
                Message::SCAFFOLD_PREFIXED->getMessage($result->warning),
            );
        }

        return $result;
    }
}
