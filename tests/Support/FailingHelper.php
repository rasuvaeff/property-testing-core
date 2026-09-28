<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

/**
 * Raises failures from a file that is not the property's own — what an
 * assertion library is to a property body: the throw site is always here, so
 * the place that tells two failures apart is the line in the body that called
 * in.
 */
final class FailingHelper
{
    private function __construct()
    {
        // Static helper; not instantiable.
    }

    public static function raise(string $message): never
    {
        throw new \RuntimeException($message);
    }

    /**
     * Recurses $depth levels before raising, so the failure of one body line
     * arrives through a stack of a different height on every input.
     *
     * @param int<0, max> $depth
     */
    public static function raiseFromDepth(string $message, int $depth): never
    {
        if ($depth > 0) {
            self::raiseFromDepth($message, $depth - 1);
        }

        throw new \RuntimeException($message);
    }
}
