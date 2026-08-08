<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use Random\Engine\Mt19937;
use Random\IntervalBoundary;
use Random\Randomizer;

/**
 * Seedable, deterministic pseudo-random number generator.
 *
 * Two instances created with the same seed produce identical sequences, which
 * is what makes counterexamples reproducible. Backed by an object-scoped
 * Mersenne Twister (MT19937) engine via ext-random's {@see Randomizer}, so it
 * is independent of PHP's global mt_rand state — important inside a test runner
 * where other code may draw random numbers between runs.
 *
 * @api
 */
final readonly class Random
{
    private Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /**
     * Uniform integer in the inclusive range [$min, $max].
     */
    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /**
     * Uniform float in the half-open range [0.0, 1.0).
     */
    public function float(): float
    {
        return $this->randomizer->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen);
    }

    /**
     * Random byte string of the given length (bytes in 0..255).
     */
    public function bytes(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        return $this->randomizer->getBytes($length);
    }
}
