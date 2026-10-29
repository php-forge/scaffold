<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use Internal\Container\Container;
use Testo\Common\PluginConfigurator;
use Testo\Pipeline\InterceptorCollector;

/**
 * Registers the {@see StrictTestInterceptor} that fails a test on any PHP warning, notice or deprecation, and on zero
 * assertions.
 */
final class StrictTestPlugin implements PluginConfigurator
{
    /**
     * Adds the strict interceptor to the test pipeline of the suite.
     *
     * @param Container $container Container of the suite.
     */
    public function configure(Container $container): void
    {
        $container->get(InterceptorCollector::class)->addInterceptor(new StrictTestInterceptor());
    }
}
