<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Yields a {@see DrawnEngine}. The engine draws nothing at generation time
 * — its randomness is consumed by the body, through the draw tape — so the
 * node is a leaf: what shrinks is the tape, as `draw#N` pseudo-arguments.
 *
 * @implements ArbitraryInterface<\Random\Engine>
 * @api
 */
final readonly class RandomEngineArbitrary implements ArbitraryInterface
{
    /**
     * @return Shrinkable<\Random\Engine>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        /** @var \Random\Engine $engine */
        $engine = new DrawnEngine();

        return Shrinkable::leaf($engine);
    }
}
