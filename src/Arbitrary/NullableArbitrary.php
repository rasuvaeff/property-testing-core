<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Wraps another arbitrary and additionally yields `null` with roughly even odds.
 *
 * Shrinking prefers `null` over descending into the inner value's tree.
 *
 * @implements ArbitraryInterface<mixed>
 * @api
 */
final readonly class NullableArbitrary implements ArbitraryInterface
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
