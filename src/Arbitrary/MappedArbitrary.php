<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Enumerable;
use Rasuvaeff\PropertyTesting\Internal\Domain;
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
 * @implements Enumerable<TOutput>
 * @api
 */
final readonly class MappedArbitrary implements Enumerable
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

    /**
     * The source's size: a map that sends two source values to one output
     * walks both, so this is an upper bound on the distinct outputs.
     */
    #[\Override]
    public function domainSize(): ?int
    {
        return Domain::sizeOf($this->inner);
    }

    /**
     * @throws \LogicException When the source has no finite domain ({@see domainSize()} is null).
     */
    #[\Override]
    public function enumerate(): iterable
    {
        if (!$this->inner instanceof Enumerable) {
            throw new \LogicException('Gen::map(): the source generator has no finite domain to enumerate');
        }

        foreach ($this->inner->enumerate() as $node) {
            yield $node->map($this->map);
        }
    }
}
