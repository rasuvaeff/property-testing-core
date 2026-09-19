<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\DrawContext;

/**
 * A {@see \Random\Engine} whose output is drawn from the property's own
 * replay tape: every {@see generate()} is one in-body {@see Gen::draw()} of
 * eight bytes, so the random decisions of the code under test are recorded,
 * replayed by position on shrink trials, and shrunk through the bytes' own
 * tree toward `"\0"` — which {@see \Random\Randomizer} maps to the lower end
 * of a range and to near-identity shuffles.
 *
 * Valid only inside a property run, like {@see Gen::draw()}: outside one the
 * first `generate()` throws. Not seedable — the tape is the seed.
 *
 * @api
 */
final readonly class DrawnEngine implements \Random\Engine
{
    /** Bytes per draw: what a 64-bit {@see \Random\Randomizer} consumes at a time. */
    private const int BYTES = 8;

    #[\Override]
    public function generate(): string
    {
        /** @var string */
        return DrawContext::draw(Gen::bytes(self::BYTES, self::BYTES));
    }
}
