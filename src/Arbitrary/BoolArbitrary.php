<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates booleans. false is the "smaller" boolean: true shrinks to false,
 * false is terminal.
 *
 * @implements ArbitraryInterface<bool>
 * @api
 */
final readonly class BoolArbitrary implements ArbitraryInterface
{
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $value = $random->int(0, 1) === 1;

        /** @var list<Shrinkable<bool>> $shrinks */
        $shrinks = $value ? [Shrinkable::leaf(value: false)] : [];

        return Shrinkable::of($value, static fn(): array => $shrinks);
    }
}
