<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * Describes a space of random values with integrated shrinking.
 *
 * An arbitrary produces a {@see Shrinkable} via {@see generate()}: the
 * generated value together with a lazy tree of smaller candidates, each
 * carrying its own subtree. The property runner reads the value, and on a
 * failure descends through the tree, accepting the first candidate that still
 * fails, until no smaller value fails — yielding a minimal counterexample.
 *
 * Because shrink candidates are attached at generation time, combinators such
 * as {@see Gen::map()} and {@see Gen::flatMap()} shrink in the source domain
 * and re-apply their transformation; implementations never need to invert a
 * transformed value.
 *
 * @template TValue The type of the generated value
 * @api
 */
interface ArbitraryInterface
{
    /**
     * Produce one random value from this arbitrary's space, together with its
     * shrink tree. Candidates must be ordered most aggressive first (typically
     * toward a zero/empty/identity element) and every branch of the tree must
     * be finite, so shrinking terminates.
     *
     * @return Shrinkable<TValue>
     */
    public function generate(Random $random): Shrinkable;
}
