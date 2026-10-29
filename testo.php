<?php

declare(strict_types=1);

use PHPForge\Scaffold\Tests\Support\StrictTestPlugin;
use Testo\Application\Config\{ApplicationConfig, FinderConfig, SuiteConfig};

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: new FinderConfig(
                include: ['tests'],
                exclude: ['tests/Composer', 'tests/Fixtures', 'tests/Provider', 'tests/Support'],
            ),
        ),
        new SuiteConfig(name: 'Composer', location: ['tests/Composer']),
    ],
    plugins: [new StrictTestPlugin()],
);
