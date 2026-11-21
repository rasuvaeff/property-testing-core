<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\ValueRenderer;

/**
 * The range checks every bounded generator's constructor makes, worded once:
 * the message names the generator and the values it refused, because inside
 * a {@see \Rasuvaeff\PropertyTesting\Gen::flatMap()} the caller cannot tell
 * which of the nested generators said no.
 *
 * @internal
 */
final class Bounds
{
    private function __construct()
    {
        // Static helpers; not instantiable.
    }

    /**
     * A size or length range: `0 <= $min <= $max`. A maximum of zero is a
     * valid range — the generator then produces only the empty value, which
     * is what a dependent `flatMap(intBetween(0, 3), fn ($n) => arrayOf($g, 0, $n))`
     * asks for when it draws `0`.
     *
     * @param string $generator The factory the caller knows the generator by, e.g. `Gen::arrayOf()`.
     * @param string $minName The minimum's parameter name, e.g. `minSize`.
     * @param string $maxName The maximum's parameter name, e.g. `maxSize`.
     *
     * @throws \InvalidArgumentException When the minimum is negative or above the maximum.
     */
    public static function size(string $generator, string $minName, string $maxName, int $min, int $max): void
    {
        if ($min < 0) {
            throw new \InvalidArgumentException(sprintf(
                '%s: %s must be greater than or equal to 0, got %d',
                $generator,
                $minName,
                $min,
            ));
        }

        self::ordered($generator, $minName, $maxName, $min, $max);
    }

    /**
     * An inclusive range whose ends may be equal: `$min <= $max`.
     *
     * @param string $generator The factory the caller knows the generator by, e.g. `Gen::intBetween()`.
     * @param string $minName The lower end's parameter name.
     * @param string $maxName The upper end's parameter name.
     *
     * @throws \InvalidArgumentException When the lower end is above the upper one.
     */
    public static function ordered(string $generator, string $minName, string $maxName, int|float $min, int|float $max): void
    {
        if ($min > $max) {
            throw new \InvalidArgumentException(sprintf(
                '%s: %s must be less than or equal to %s, got %s and %s',
                $generator,
                $minName,
                $maxName,
                ValueRenderer::render($min),
                ValueRenderer::render($max),
            ));
        }
    }
}
