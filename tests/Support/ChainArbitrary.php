<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Deterministic arbitrary whose shrink tree is a single chain
 * `depth -> depth-1 -> ... -> 0`: an always-failing property accepts exactly
 * `depth` shrink steps, which pins shrink-step accounting and caps precisely.
 */
final readonly class ChainArbitrary implements ArbitraryInterface
{
    public function __construct(
        private int $depth,
    ) {}

    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        return self::node($this->depth);
    }

    private static function node(int $value): Shrinkable
    {
        return $value === 0
            ? Shrinkable::leaf(0)
            : Shrinkable::of($value, static fn(): array => [self::node($value - 1)]);
    }
}
