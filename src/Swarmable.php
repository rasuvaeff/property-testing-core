<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * A generator that picks among a fixed, countable set of variants and can hand
 * back a copy of itself restricted to some of them — the seam
 * {@see \Rasuvaeff\PropertyTesting\Arbitrary\SwarmArbitrary} needs to do swarm
 * testing over it.
 *
 * Positions, not values, are the currency: a variant is a value in
 * {@see \Rasuvaeff\PropertyTesting\Arbitrary\OneOfArbitrary}, a
 * `[weight, arbitrary]` pair in
 * {@see \Rasuvaeff\PropertyTesting\Arbitrary\FrequencyArbitrary} and a command
 * generator in {@see \Rasuvaeff\PropertyTesting\Arbitrary\CommandSequenceArbitrary},
 * and an index is the one description all three share.
 *
 * Implement it on a custom choice generator to make it swarmable. Everything
 * the restricted copy is not asked about — sizes, weights, the initial model —
 * must survive unchanged: a swarm restricts the alphabet and nothing else.
 *
 * @template TValue
 * @extends ArbitraryInterface<TValue>
 * @api
 */
interface Swarmable extends ArbitraryInterface
{
    /**
     * How many variants this generator chooses among. Must be positive: a
     * choice generator with nothing to choose from is not a choice, and
     * {@see \Rasuvaeff\PropertyTesting\Arbitrary\SwarmArbitrary} rejects a
     * source that reports otherwise rather than drawing from an empty
     * alphabet. Deliberately typed `int` and not `int<1, max>` — the bound is
     * a promise implementations make, and a swarm still checks it at runtime
     * because it cannot analyse the implementations it will be handed.
     */
    public function variantCount(): int;

    /**
     * A copy of this generator that chooses only among the variants at
     * $indices.
     *
     * @param list<int> $indices Variant positions to keep, each in
     *        `[0, variantCount() - 1]`; an index outside that range is a
     *        programmer error and throws.
     *
     * @return self<TValue>
     */
    public function withVariants(array $indices): self;
}
