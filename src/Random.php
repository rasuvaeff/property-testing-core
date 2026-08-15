<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use Random\Engine\Mt19937;
use Random\IntervalBoundary;
use Random\Randomizer;
use Rasuvaeff\PropertyTesting\Runner\EdgeCases;

/**
 * Seedable, deterministic pseudo-random number generator.
 *
 * Two instances created with the same seed produce identical sequences, which
 * is what makes counterexamples reproducible. Backed by an object-scoped
 * Mersenne Twister (MT19937) engine via ext-random's {@see Randomizer}, so it
 * is independent of PHP's global mt_rand state — important inside a test runner
 * where other code may draw random numbers between runs.
 *
 * It also carries the {@see EdgeCases} choice, because the generators that act
 * on it already receive this and nothing else: threading a second parameter
 * through every {@see ArbitraryInterface} would change a published signature to
 * say something the source of randomness can say instead.
 *
 * @api
 */
final readonly class Random
{
    private Randomizer $randomizer;

    /**
     * @param int $seed The seed; two instances with the same one produce the same sequence.
     * @param EdgeCases $edgeCases Whether the numeric generators keep biasing toward boundary values.
     */
    public function __construct(
        int $seed,
        private EdgeCases $edgeCases = EdgeCases::Mixin,
    ) {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /**
     * Whether this draw should be an edge value rather than a uniform one.
     *
     * The roll happens either way. Skipping it under {@see EdgeCases::None}
     * would shift every later draw and make the same seed mean something else
     * in the two modes; consuming it keeps them aligned, so switching the mode
     * changes which values are edges and not the whole sequence.
     *
     * @param int $denominator The odds: one draw in this many is an edge value.
     */
    public function drawsEdgeCase(int $denominator): bool
    {
        $roll = $this->int(1, $denominator);

        return $roll === 1 && $this->edgeCases === EdgeCases::Mixin;
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
