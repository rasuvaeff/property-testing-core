<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Element-wise shrinking of a fixed set of components — the positions of a
 * tuple, the fields of a record, the elements of a list once its length is
 * settled: one component at a time, in key order, through that component's own
 * shrink tree, every other component kept as it is.
 *
 * The candidate order is part of the observable contract — a
 * {@see \Rasuvaeff\PropertyTesting\CounterExample::$path} indexes into it and
 * the minimised value depends on it — so every arbitrary that shrinks this way
 * goes through here instead of spelling the loop out again.
 *
 * @internal
 */
final class ComponentShrinks
{
    private function __construct()
    {
        // Static helper; not instantiable.
    }

    /**
     * @template TComponents of array<array-key, Shrinkable>
     * @template TResult
     *
     * @param TComponents $components
     * @param \Closure(TComponents): Shrinkable<TResult> $rebuild Builds the candidate from the
     *        components with one of them replaced; the keys and their order never change.
     *
     * @return \Generator<int, Shrinkable<TResult>>
     */
    public static function of(array $components, \Closure $rebuild): \Generator
    {
        foreach ($components as $key => $component) {
            foreach ($component->shrinks() as $smaller) {
                /** @var TComponents $candidate */
                $candidate = array_replace($components, [$key => $smaller]);

                yield $rebuild($candidate);
            }
        }
    }
}
