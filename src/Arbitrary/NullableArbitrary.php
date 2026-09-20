<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Enumerable;
use Rasuvaeff\PropertyTesting\Internal\Domain;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Wraps another arbitrary and additionally yields `null` with roughly even odds.
 *
 * Shrinking prefers `null` over descending into the inner value's tree.
 *
 * @implements Enumerable<mixed>
 * @api
 */
final readonly class NullableArbitrary implements Enumerable
{
    public function __construct(
        private ArbitraryInterface $inner,
    ) {}

    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        /** @var Shrinkable<mixed> $result */
        $result = $random->int(0, 1) === 1
            ? Shrinkable::leaf(null)
            : $this->wrap($this->inner->generate($random));

        return $result;
    }

    /**
     * One more than the inner domain, when there is one to count.
     */
    #[\Override]
    public function domainSize(): ?int
    {
        $inner = Domain::sizeOf($this->inner);

        return $inner === null ? null : Domain::plus($inner, 1);
    }

    /**
     * Null first, then the inner domain in its own order.
     *
     * @throws \LogicException When the source has no finite domain ({@see domainSize()} is null).
     */
    #[\Override]
    public function enumerate(): iterable
    {
        if (!$this->inner instanceof Enumerable) {
            throw new \LogicException('Gen::nullable(): the inner generator has no finite domain to enumerate');
        }

        yield Shrinkable::leaf(null);

        foreach ($this->inner->enumerate() as $node) {
            yield $this->wrap($node);
        }
    }

    private function wrap(Shrinkable $inner): Shrinkable
    {
        /** @var Shrinkable<mixed> $result */
        $result = Shrinkable::of($inner->value, fn() => $this->shrinksFor($inner));

        return $result;
    }

    /**
     * @return iterable<Shrinkable<mixed>>
     */
    private function shrinksFor(Shrinkable $inner): iterable
    {
        /** @var Shrinkable<mixed> $null */
        $null = Shrinkable::leaf(null);
        yield $null;

        foreach ($inner->shrinks() as $smaller) {
            yield $this->wrap($smaller);
        }
    }
}
