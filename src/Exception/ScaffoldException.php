<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Exception;

use RuntimeException;

/**
 * Represents a scaffold failure that must fail the Composer command or the console command that triggered it.
 */
final class ScaffoldException extends RuntimeException {}
