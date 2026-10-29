<?php

declare(strict_types=1);

namespace PHPForge\Scaffold;

use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Script\{Event, ScriptEvents};
use PHPForge\Scaffold\Config\ProjectConfig;
use PHPForge\Scaffold\Console\{ComposerIoWriter, Message as ConsoleMessage};
use PHPForge\Scaffold\Exception\{Message, ScaffoldException};
use PHPForge\Scaffold\Manifest\{ManifestExpander, ManifestLoader, ManifestSchema};
use PHPForge\Scaffold\Scaffold\{FileApplier, PathResolver, ScaffoldSummary, Scaffolder};
use PHPForge\Scaffold\Scaffold\Lock\LockFile;
use PHPForge\Scaffold\Security\PathValidator;
use Throwable;

use function dirname;

/**
 * Listens to Composer script events and triggers the scaffold workflow.
 */
final class EventSubscriber implements EventSubscriberInterface
{
    /**
     * Whether `post-install-cmd` or `post-update-cmd` fired in this process, so `post-create-project-cmd` does not run
     * the workflow again.
     */
    private static bool $scaffoldRan = false;

    /**
     * Returns the Composer script events handled by this subscriber.
     *
     * @return array<string, string> Event name => handler method.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'onPostCreateProject',
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate',
        ];
    }

    /**
     * Runs the scaffold workflow for `post-create-project-cmd`, unless `post-install-cmd` or `post-update-cmd` already
     * fired in this process.
     *
     * @param Event $event The Composer event object.
     */
    public function onPostCreateProject(Event $event): void
    {
        if (self::$scaffoldRan) {
            return;
        }

        $this->runScaffold($event);
    }

    /**
     * Runs the scaffold workflow for `post-install-cmd`.
     *
     * @param Event $event The Composer event object.
     */
    public function onPostInstall(Event $event): void
    {
        self::$scaffoldRan = true;

        $this->runScaffold($event);
    }

    /**
     * Runs the scaffold workflow for `post-update-cmd`.
     *
     * @param Event $event The Composer event object.
     */
    public function onPostUpdate(Event $event): void
    {
        self::$scaffoldRan = true;

        $this->runScaffold($event);
    }

    /**
     * Runs the scaffold workflow for the root package unless `extra.scaffold.auto` is `false`, ends with a summary line
     * when a file was written or something needs attention, and fails the Composer command on errors.
     *
     * @param Event $event Composer script event that provides the Composer instance and IO.
     *
     * @throws ScaffoldException after the messages and the summary line are written, when the lock file cannot be read
     * or written, the manifest of an allowed provider cannot be loaded, or a file fails to apply.
     */
    private function runScaffold(Event $event): void
    {
        $composer = $event->getComposer();
        $io = $event->getIO();

        $config = ProjectConfig::fromExtra($composer->getPackage()->getExtra());

        if (!$config->auto) {
            $io->write(ConsoleMessage::SCAFFOLD_AUTO_DISABLED->getMessage());

            return;
        }

        $vendorDirRaw = $composer->getConfig()->get('vendor-dir');

        if ($vendorDirRaw === '') {
            $io->writeError(ConsoleMessage::SCAFFOLD_VENDOR_DIR_UNRESOLVABLE->getMessage());

            return;
        }

        $vendorDir = PathResolver::realpathOrFallback($vendorDirRaw);
        $projectRoot = PathResolver::realpathOrFallback(dirname($composer->getConfig()->getConfigSource()->getName()));

        for ($i = 0; $i < $config->ignoredEntries; $i++) {
            $io->writeError(ConsoleMessage::SCAFFOLD_ALLOWED_ENTRY_IGNORED->getMessage());
        }

        $out = new ComposerIoWriter($io);
        $summary = new ScaffoldSummary();

        $scaffolder = new Scaffolder(
            new ManifestLoader(new ManifestSchema(), new ManifestExpander()),
            new FileApplier($config, new PathValidator(), $out),
            new LockFile($projectRoot),
            $out,
            $summary,
        );

        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $installationManager = $composer->getInstallationManager();

        $installPaths = [];

        foreach ($localRepo->getPackages() as $package) {
            $path = $installationManager->getInstallPath($package);

            if ($path !== null) {
                $installPaths[$package->getName()] = $path;
            }
        }

        $failure = null;

        try {
            $scaffolder->scaffold(
                $config,
                $localRepo->getPackages(),
                $projectRoot,
                $vendorDir,
                $installPaths,
            );
        } catch (ScaffoldException $e) {
            // The scaffolder already wrote the message.
            $failure = $e;
        } catch (Throwable $e) {
            $message = Message::SCAFFOLD_ABORTED->getMessage($e->getMessage());

            $io->writeError($message);

            $summary->errors++;

            $failure = new ScaffoldException(
                $message,
                0,
                $e,
            );
        }

        $line = $summary->line();

        if ($line !== null) {
            $io->write($line);
        }

        if ($failure !== null) {
            throw $failure;
        }

        if ($summary->errors > 0) {
            throw new ScaffoldException(
                Message::SCAFFOLD_FILES_FAILED->getMessage(
                    $summary->errors,
                    Message::plural(
                        $summary->errors,
                        Message::SCAFFOLD_FRAGMENT_FAILED_FILE,
                        Message::SCAFFOLD_FRAGMENT_FAILED_FILES,
                    ),
                ),
            );
        }
    }
}
