<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

/**
 * Runs a callable with every PHP diagnostic turned into an exception —
 * including the ones `@` hides, which still reach a custom error handler.
 */
final class NoDiagnostics
{
    /**
     * @template T
     *
     * @param \Closure(): T $action
     *
     * @return T
     */
    public static function run(\Closure $action): mixed
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            return $action();
        } finally {
            restore_error_handler();
        }
    }
}
