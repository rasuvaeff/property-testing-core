<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

/**
 * Calls a method named at run time on a machine object — the one place the
 * rule-based façade goes through a string, kept out of the classes that
 * would otherwise each spell the same reflection.
 *
 * @internal
 */
final class Invoke
{
    private function __construct() {}

    /**
     * Call $method on $target with $arguments as named arguments.
     *
     * @param object $target The machine.
     * @param non-empty-string $method The method to call.
     * @param array<string, mixed> $arguments By parameter name.
     */
    public static function method(object $target, string $method, array $arguments = []): mixed
    {
        return (new \ReflectionMethod($target, $method))->invokeArgs($target, $arguments);
    }
}
