<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Deterministic arbitrary whose value is always 10 and whose shrink
 * enumeration yields one passing candidate (0) and then throws: pins that a
 * candidate enumeration breaking mid-way ends the enumeration instead of
 * escaping the descent with the counterexample.
 */
final readonly class ThrowingShrinkArbitrary implements ArbitraryInterface
{
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        return Shrinkable::of(10, static function (): \Generator {
            yield Shrinkable::leaf(0);

            throw new \LogicException('shrink tree broke');
        });
    }
}
