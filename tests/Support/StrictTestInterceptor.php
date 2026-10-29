<?php

declare(strict_types=1);

namespace PHPForge\Scaffold\Tests\Support;

use RuntimeException;
use Testo\Core\Context\{TestInfo, TestResult};
use Testo\Core\Value\Status;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

use function error_reporting;
use function implode;
use function restore_error_handler;
use function set_error_handler;

/**
 * Fails a passed test that raised a PHP warning, notice or deprecation, and turns a risky test (no assertion) into a
 * failure.
 *
 * Runs the test with `error_reporting(E_ALL)`; errors silenced with `@` are left to PHP. Sits between the assertion
 * collector and the expectation checks, so it sees the risky status and wraps the lifecycle hooks too.
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_ASSERTIONS + 10)]
final class StrictTestInterceptor implements TestRunInterceptor
{
    /**
     * Runs the test while collecting PHP errors and returns its result, failed when the test passed with collected
     * errors or was risky.
     *
     * @param TestInfo $info Test to run.
     * @param callable(TestInfo): TestResult $next Rest of the pipeline.
     *
     * @return TestResult Result of the test.
     */
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        $errors = [];

        $reporting = error_reporting(E_ALL);

        set_error_handler(
            static function (int $severity, string $message, string $file, int $line) use (&$errors): bool {
                if ((error_reporting() & $severity) === 0) {
                    return false;
                }

                $errors[] = "PHP error {$severity}: {$message} in {$file}:{$line}";

                return true;
            },
        );

        try {
            $result = $next($info);
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }

        if ($result->status === Status::Passed && $errors !== []) {
            return $result
                ->with(status: Status::Failed)
                ->withFailure(new RuntimeException(implode("\n", $errors)));
        }

        if ($result->status === Status::Risky) {
            return $result
                ->with(status: Status::Failed)
                ->withFailure(new RuntimeException('Test performed no assertion.'));
        }

        return $result;
    }
}
