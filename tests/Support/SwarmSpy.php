<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

use ArrayObject;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Swarmable;

/**
 * A {@see Swarmable} that records the variant subsets it is restricted to.
 *
 * The subset a swarm draws is invisible from the outside — it is neither the
 * generated value nor part of the counterexample — so the tests that pin it
 * (non-empty, in range, one draw per case) need the seam itself to report what
 * it was asked for. Restricting returns a new instance, so the log is a shared
 * object rather than a property of one.
 *
 * @template TValue
 * @implements Swarmable<TValue>
 */
final readonly class SwarmSpy implements Swarmable
{
    /**
     * @param ArbitraryInterface<TValue> $inner Produces the values; restricted alongside this spy.
     * @param ArrayObject<int, list<int>> $log Every subset this spy or its copies were asked for, in order.
     * @param int $variants What to report as the variant count — settable so a source claiming none can be tested.
     */
    public function __construct(
        private ArbitraryInterface $inner,
        private ArrayObject $log,
        private int $variants,
    ) {}

    /**
     * @return Shrinkable<TValue>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        return $this->inner->generate($random);
    }

    #[\Override]
    public function variantCount(): int
    {
        return $this->variants;
    }

    /**
     * @param list<int> $indices
     *
     * @return self<TValue>
     */
    #[\Override]
    public function withVariants(array $indices): self
    {
        $this->log->append($indices);

        $inner = $this->inner;

        return new self(
            $inner instanceof Swarmable ? $inner->withVariants($indices) : $inner,
            $this->log,
            count($indices),
        );
    }
}
