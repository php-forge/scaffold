<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Provider;

use Composer\Script\ScriptEvents;
use PHPForge\Scaffold\Tests\Functional\PostCreateProjectTest;

/**
 * Data provider for {@see PostCreateProjectTest} test cases.
 */
final class PostCreateProjectProvider
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function installEvents(): iterable
    {
        yield 'post-install-cmd' => [ScriptEvents::POST_INSTALL_CMD];
        yield 'post-update-cmd' => [ScriptEvents::POST_UPDATE_CMD];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scaffoldEvents(): iterable
    {
        yield 'post-create-project-cmd' => [ScriptEvents::POST_CREATE_PROJECT_CMD];
        yield from self::installEvents();
    }
}
