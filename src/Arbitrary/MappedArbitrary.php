<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Transforms each value produced by a delegate arbitrary through a pure function.
 *
 * The whole shrink tree is mapped: shrinking happens in the inner (source)
 * domain and the function is re-applied to every candidate, so the shrunk
 * counterexample is reported in the transformed domain. The function must be
 * pure — it runs once per generated value and once per visited candidate.
 *
 * @template TInner
 * @template TOutput
 * @implements ArbitraryInterface<TOutput>
 * @api
 */
final readonly class MappedArbitrary implements ArbitraryInterface
{
    /**
     * @param ArbitraryInterface<TInner> $inner
     * @param Closure(TInner): TOutput $map
     */
    public function __construct(
        private ArbitraryInterface $inner,
        private Closure $map,
    ) {}

    /**
     * @return Shrinkable<TOutput>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        return $this->inner->generate($random)->map($this->map);
    }
}
