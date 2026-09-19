<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * A generator whose whole domain can be counted and walked — the seam the
 * exhaustive mode of {@see Runner\PropertyConfig::$exhaustive} needs: when
 * every parameter's generator implements it and the product of their sizes
 * fits the budget, the random phase enumerates the product instead of
 * sampling it, and a pass is a proof over the parameters rather than a
 * probability statement.
 *
 * A generator implements it when its domain is finite *in some
 * configurations*: {@see domainSize()} answers `null` for the others (a
 * nullable over an unbounded inner, a tuple with one unbounded element), and
 * the mode declines to random for that property — the way
 * {@see Gen::oneOf()} refuses generators rather than guessing.
 *
 * The built-in implementers: `Gen::constant()`, `Gen::bool()`,
 * `Gen::intBetween()` (and `int()`, at a size no budget accepts),
 * `Gen::elements()`/`enum()`/`oneOf()`, `Gen::nullable()`, `Gen::tuple()`,
 * `Gen::record()`, `Gen::map()`, `Gen::filter()` and `Gen::withEdgeCases()`
 * over enumerable sources.
 *
 * @template TValue
 * @extends ArbitraryInterface<TValue>
 * @api
 */
interface Enumerable extends ArbitraryInterface
{
    /**
     * How many distinct values {@see enumerate()} walks, or null when the
     * domain is not finite in this configuration. Saturates at
     * `PHP_INT_MAX` rather than overflowing — a budget compares against it,
     * nothing computes with it. An upper bound is acceptable where the exact
     * count is not known without walking (a filter over a finite source).
     *
     * @return ?int<1, max>
     */
    public function domainSize(): ?int;

    /**
     * Every value of the domain, once each, as a shrinkable node with the
     * same tree {@see generate()} would give it, in a fixed order that does
     * not depend on any seed. Only meaningful when {@see domainSize()} is not
     * null; an implementation may throw otherwise.
     *
     * @return iterable<Shrinkable<TValue>>
     */
    public function enumerate(): iterable;
}
