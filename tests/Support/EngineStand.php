<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

/**
 * Stands where the engine's own file stands in a stack: it calls into failing
 * code, so a failure raised through it has this file's frame between the throw
 * and the test file that asked for it — the shape every real trace has, where
 * the runner's frame separates the body from the adapter's call.
 */
final class EngineStand
{
    private function __construct()
    {
        // Static helper; not instantiable.
    }

    public static function raise(string $message): never
    {
        FailingHelper::raise($message);
    }
}
