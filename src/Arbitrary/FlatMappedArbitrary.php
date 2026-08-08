<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Dependent generators (monadic bind): each value produced by the source
 * arbitrary is fed into a closure that returns the arbitrary generating the
 * final value. Use it when one input's domain depends on another, e.g. a list
 * plus a valid index into that list — instead of discarding invalid pairs via
 * {@see \Rasuvaeff\PropertyTesting\Assume::that()}.
 *
 * Shrinking works on both levels: first the source value shrinks (the closure
 * is re-applied and the dependent arbitrary regenerates with the same captured
 * seed, so runs stay reproducible), then the dependent value shrinks through
 * its own tree with the source value held fixed. The closure must be pure — it
 * runs once per generated value and once per visited source candidate.
 *
 * @template TInner
 * @template TOutput
 * @implements ArbitraryInterface<TOutput>
 * @api
 */
final readonly class FlatMappedArbitrary implements ArbitraryInterface
{
    /**
     * @param ArbitraryInterface<TInner> $inner
     * @param Closure(TInner): ArbitraryInterface<TOutput> $flatMap
     */
    public function __construct(
        private ArbitraryInterface $inner,
        private Closure $flatMap,
    ) {}

    /**
     * @return Shrinkable<TOutput>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $outer = $this->inner->generate($random);

        // Capture a seed so the dependent arbitrary regenerates deterministically
        // every time the outer value shrinks — reported seeds stay reproducible.
        $seed = $random->int(PHP_INT_MIN, PHP_INT_MAX);

        return $this->bind($outer, $seed);
    }

    /**
     * @param Shrinkable<TInner> $outer
     *
     * @return Shrinkable<TOutput>
     */
    private function bind(Shrinkable $outer, int $seed): Shrinkable
    {
        /** @var mixed $arbitrary */
        $arbitrary = ($this->flatMap)($outer->value);

        if (!$arbitrary instanceof ArbitraryInterface) {
            throw new \InvalidArgumentException(sprintf(
                'flatMap closure must return an ArbitraryInterface, got %s',
                get_debug_type($arbitrary),
            ));
        }

        /** @var Shrinkable<TOutput> $inner */
        $inner = $arbitrary->generate(new Random($seed));

        return $this->node($outer, $inner, $seed);
    }

    /**
     * @param Shrinkable<TInner>  $outer
     * @param Shrinkable<TOutput> $inner
     *
     * @return Shrinkable<TOutput>
     */
    private function node(Shrinkable $outer, Shrinkable $inner, int $seed): Shrinkable
    {
        /** @var Shrinkable<TOutput> $result */
        $result = Shrinkable::of($inner->value, fn() => $this->shrinksFor($outer, $inner, $seed));

        return $result;
    }

    /**
     * @param Shrinkable<TInner>  $outer
     * @param Shrinkable<TOutput> $inner
     *
     * @return iterable<Shrinkable<TOutput>>
     */
    private function shrinksFor(Shrinkable $outer, Shrinkable $inner, int $seed): iterable
    {
        // 1. Shrink the source value: rebuild the dependent arbitrary from the
        //    smaller source value and regenerate with the captured seed.
        foreach ($outer->shrinks() as $smallerOuter) {
            yield $this->bind($smallerOuter, $seed);
        }

        // 2. Shrink the dependent value through its own tree, keeping the
        //    source value fixed.
        foreach ($inner->shrinks() as $smallerInner) {
            yield $this->node($outer, $smallerInner, $seed);
        }
    }
}
