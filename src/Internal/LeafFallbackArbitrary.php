<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\Arbitrary\FrequencyArbitrary;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Swarmable;

/**
 * One level of {@see \Rasuvaeff\PropertyTesting\Gen::recursive()}: the choice
 * between the leaf and the wrapped branch, with the leaf offered as the first
 * shrink candidate of whatever was chosen.
 *
 * {@see FrequencyArbitrary} shrinks inside the branch it picked and never
 * across branches, so a nested structure could shrink to an empty container
 * but never to the plain value it wraps — `[[[1]]]` reached `[]`, not `1`. The
 * leaf's seed is drawn at generation time, so the candidate is the same on
 * every enumeration and on every replay.
 *
 * @template TValue
 * @implements Swarmable<TValue>
 * @internal
 */
final readonly class LeafFallbackArbitrary implements Swarmable
{
    /**
     * @param FrequencyArbitrary<TValue> $choice The leaf-or-wrapped choice of this level.
     * @param ArbitraryInterface<TValue> $leaf
     */
    public function __construct(
        private FrequencyArbitrary $choice,
        private ArbitraryInterface $leaf,
    ) {}

    /**
     * @return Shrinkable<TValue>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $leafSeed = $random->int(PHP_INT_MIN, PHP_INT_MAX);
        $node = $this->choice->generate($random);

        return Shrinkable::of($node->value, function () use ($node, $leafSeed): \Generator {
            // The runner skips a candidate equal to the current value, so when
            // the choice already was the leaf this costs one comparison.
            yield $this->leaf->generate(new Random($leafSeed));

            yield from $node->shrinks();
        });
    }

    #[\Override]
    public function variantCount(): int
    {
        return $this->choice->variantCount();
    }

    #[\Override]
    public function withVariants(array $indices): self
    {
        return new self($this->choice->withVariants($indices), $this->leaf);
    }
}
