<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Weighted choice among several arbitraries: each `[weight, arbitrary]` pair is
 * picked with probability proportional to its (positive integer) weight, then
 * the chosen arbitrary produces the value.
 *
 * The chosen branch's shrink tree is returned as-is, so shrinking stays within
 * the branch that actually generated the value.
 *
 * @template TValue
 * @implements ArbitraryInterface<TValue>
 * @api
 */
final readonly class FrequencyArbitrary implements ArbitraryInterface
{
    /** @var non-empty-list<array{0: int, 1: ArbitraryInterface<TValue>}> */
    private array $pairs;

    private int $totalWeight;

    /**
     * @param iterable<mixed> $pairs Each item must be `[int $weight, ArbitraryInterface $arbitrary]` with $weight >= 1.
     */
    public function __construct(iterable $pairs)
    {
        $normalized = [];
        $total = 0;

        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                throw new \InvalidArgumentException('Frequency pair must be [int $weight, ArbitraryInterface $arbitrary]');
            }

            $weight = $pair[0] ?? null;
            $arbitrary = $pair[1] ?? null;

            if (!is_int($weight) || $weight < 1) {
                throw new \InvalidArgumentException('Frequency weight must be an integer greater than or equal to 1');
            }
            if (!$arbitrary instanceof ArbitraryInterface) {
                throw new \InvalidArgumentException('Frequency pair must contain an ArbitraryInterface');
            }

            $total += $weight;
            $normalized[] = [$weight, $arbitrary];
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('Frequency requires at least one weighted arbitrary');
        }

        $this->pairs = $normalized;
        $this->totalWeight = $total;
    }

    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $target = $random->int(1, $this->totalWeight);

        foreach ($this->pairs as [$weight, $arbitrary]) {
            $target -= $weight;

            if ($target <= 0) {
                return $arbitrary->generate($random);
            }
        }

        // $target starts in [1, totalWeight] and the weights sum to totalWeight,
        // so the cumulative subtraction always crosses zero on some branch; the
        // loop therefore always returns. This throw only satisfies the compiler.
        throw new \LogicException('Frequency selection failed to pick a branch');
    }
}
