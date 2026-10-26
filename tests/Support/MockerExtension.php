<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use PHPUnit\Event\Test\{Finished, FinishedSubscriber, PreparationStarted, PreparationStartedSubscriber};
use PHPUnit\Event\TestSuite\{Started, StartedSubscriber};
use PHPUnit\Runner\Extension\{Extension, Facade, ParameterCollection};
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;
use Xepozz\InternalMocker\{Mocker, MockerState};

/**
 * PHPUnit extension that registers internal-function mocks for scaffold namespaces.
 *
 * Wires {@see \Xepozz\InternalMocker\Mocker} into the PHPUnit event loop so the scaffold source namespaces can
 * intercept selected PHP built-ins (`is_readable`, `mkdir`, `is_dir`, `file_put_contents`) during tests that need to
 * simulate filesystem failures or race conditions beyond what a real temporary directory can reproduce.
 */
final class MockerExtension implements Extension
{
    /**
     * Registers event subscribers that initialize and reset mock state across the PHPUnit lifecycle.
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class implements StartedSubscriber {
                public function notify(Started $event): void
                {
                    MockerExtension::load();
                }
            },
            new class implements PreparationStartedSubscriber {
                public function notify(PreparationStarted $event): void
                {
                    MockerState::resetState();
                    MockerExtension::resetDefaults();
                }
            },
            new class implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    MockerState::resetState();
                    MockerExtension::resetDefaults();
                }
            },
        );
    }

    /**
     * Loads configured function mocks into scaffold namespaces and snapshots their initial state.
     */
    public static function load(): void
    {
        $mocks = [
            ['namespace' => 'PHPForge\\Scaffold\\Scaffold\\Lock', 'name' => 'is_readable'],
            ['namespace' => 'PHPForge\\Scaffold\\Scaffold\\Lock', 'name' => 'file_get_contents'],
            ['namespace' => 'PHPForge\\Scaffold\\Scaffold\\Lock', 'name' => 'file_put_contents'],
            ['namespace' => 'PHPForge\\Scaffold\\Scaffold\\Lock', 'name' => 'rename'],
            ['namespace' => 'PHPForge\\Scaffold\\Scaffold\\Lock', 'name' => 'hash_file'],
            ['namespace' => 'PHPForge\\Scaffold\\Scaffold\\Modes', 'name' => 'file_put_contents'],
            ['namespace' => 'PHPForge\\Scaffold\\Scaffold\\Modes', 'name' => 'file_get_contents'],
            ['namespace' => 'PHPForge\\Scaffold\\Scaffold\\Modes', 'name' => 'copy'],
            ['namespace' => 'PHPForge\\Scaffold\\Scaffold', 'name' => 'mkdir'],
            ['namespace' => 'PHPForge\\Scaffold\\Scaffold', 'name' => 'is_dir'],
            ['namespace' => 'PHPForge\\Scaffold\\Manifest', 'name' => 'file_get_contents'],
            ['namespace' => 'PHPForge\\Scaffold\\Services', 'name' => 'file_get_contents'],
            ['namespace' => 'PHPForge\\Scaffold\\Services', 'name' => 'file_put_contents'],
            ['namespace' => 'PHPForge\\Scaffold\\Console\\Command', 'name' => 'getcwd'],
        ];

        $mocksPath = __DIR__ . '/../../runtime/.phpunit.cache/internal-mocker/mocks.php';
        $stubPath = __DIR__ . '/internal-mocker-stubs.php';

        /**
         * Force regeneration of the namespaced wrappers on every run so a stale cache persisted by a CI runner,
         * an opcache layer, or a previous local session with a different mock list cannot silently shadow the current
         * configuration. `Mocker::load()` uses `require_once`, so the very first require in this process binds the
         * namespaced function symbols; deleting the cached file here ensures that require loads the freshly generated
         * content rather than an outdated copy.
         */
        if (is_file($mocksPath)) {
            unlink($mocksPath);
        }

        $mocker = new Mocker($mocksPath, $stubPath);

        $mocker->load($mocks);

        MockerState::saveState();
    }

    /**
     * Clears {@see MockerState::$defaults} via reflection between tests.
     *
     * `MockerState::resetState()` only restores `$state` to its saved snapshot but leaves the `$defaults` map
     * (populated via `addCondition(..., default: true)`) untouched. That causes a default set in one test to silently
     * shadow real function calls in every subsequent test. This helper restores clean isolation.
     */
    public static function resetDefaults(): void
    {
        $reflection = new ReflectionClass(MockerState::class);

        $property = $reflection->getProperty('defaults');

        $property->setValue(null, []);
    }
}
