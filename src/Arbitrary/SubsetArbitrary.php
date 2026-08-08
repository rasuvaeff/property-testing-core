<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates subsets of a fixed ordered set: every result is a list of distinct
 * elements of the source list, in the source list's order. Distribution is
 * uniform over the allowed sizes first (each size in `[minSize, maxSize]` is
 * equally likely), then uniform over the combinations of that size — small and
 * large subsets appear equally often, which a uniform-over-all-subsets draw
 * would not give.
 *
 * Shrinking reduces the subset's size first (empty set, halved prefixes,
 * single-element removals), then moves each kept element toward earlier
 * positions of the source list — the minimal falsifying subset therefore
 * gravitates to a short prefix of the source. Every candidate strictly
 * decreases the pair (size, sum of source positions), so the descent is finite
 * by construction; no filtering, no discards.
 *
 * @template TValue
 * @implements ArbitraryInterface<list<TValue>>
 * @api
 */
final readonly class SubsetArbitrary implements ArbitraryInterface
{
    private int $resolvedMaxSize;

    /**
     * @param list<TValue> $values The source set, as an ordered list. Duplicates
     *   (strict comparison) are rejected — a set has no duplicate members.
     * @param ?int $maxSize Null means the full source size.
     */
    public function __construct(
        private array $values,
        private int $minSize = 0,
        ?int $maxSize = null,
    ) {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException('Subset source must be a list');
        }

        $this->assertDistinct($values);

        $count = count($values);
        $resolvedMaxSize = $maxSize ?? $count;

        if ($minSize < 0) {
            throw new \InvalidArgumentException('Minimum size must be greater than or equal to 0');
        }
        if ($minSize > $resolvedMaxSize) {
            throw new \InvalidArgumentException('Minimum size must be less than or equal to maximum size');
        }
        if ($resolvedMaxSize > $count) {
            throw new \InvalidArgumentException(sprintf(
                'Maximum size %d exceeds the source size %d',
                $resolvedMaxSize,
                $count,
            ));
        }

        $this->resolvedMaxSize = $resolvedMaxSize;
    }

    /**
     * @return Shrinkable<list<TValue>>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $size = $random->int($this->minSize, $this->resolvedMaxSize);
        $count = count($this->values);

        // Partial Fisher-Yates: the first $size slots of the index pool are a
        // uniformly drawn combination; sorting restores the source order.
        $pool = $count === 0 ? [] : range(0, $count - 1);

        for ($slot = 0; $slot < $size; ++$slot) {
            $swap = $random->int($slot, $count - 1);
            [$pool[$slot], $pool[$swap]] = [$pool[$swap], $pool[$slot]];
        }

        $indices = \array_slice($pool, 0, $size);
        sort($indices);

        return $this->tree($indices);
    }

    /**
     * @param list<int> $indices Sorted, distinct source positions.
     *
     * @return Shrinkable<list<TValue>>
     */
    private function tree(array $indices): Shrinkable
    {
        $value = array_map(fn(int $index): mixed => $this->values[$index], $indices);

        return Shrinkable::of($value, function () use ($indices): \Generator {
            $size = count($indices);

            if ($size === 0) {
                return;
            }

            // 1. Size first: the empty set, halved prefixes, then dropping one
            //    element at a time — any subset of a subset is still a subset.
            if ($this->minSize === 0) {
                yield $this->tree([]);
            }

            $length = $size;
            while ($length > 1) {
                $length = intdiv($length, 2);

                if ($length >= $this->minSize) {
                    yield $this->tree(\array_slice($indices, 0, $length));
                }
            }

            if ($size - 1 >= $this->minSize) {
                foreach (array_keys($indices) as $drop) {
                    $kept = $indices;
                    unset($kept[$drop]);

                    yield $this->tree(array_values($kept));
                }
            }

            // 2. Then positions: walk each kept element toward the earliest
            //    source position still free below it (an int ladder toward the
            //    previous element's position + 1). Sortedness keeps candidates
            //    distinct, and the sum of positions strictly decreases.
            foreach ($indices as $rank => $position) {
                $floor = $rank > 0 ? $indices[$rank - 1] + 1 : 0;
                $delta = $position - $floor;

                while ($delta > 0) {
                    yield $this->tree(array_replace($indices, [$rank => $position - $delta]));

                    $delta = intdiv($delta, 2);
                }
            }
        });
    }

    /**
     * @param list<mixed> $values
     */
    private function assertDistinct(array $values): void
    {
        $scalarKeys = [];
        /** @var list<mixed> $other */
        $other = [];

        /** @var mixed $value */
        foreach ($values as $value) {
            if (is_int($value) || is_string($value)) {
                $key = (is_int($value) ? 'i:' : 's:') . $value;

                if (isset($scalarKeys[$key])) {
                    throw new \InvalidArgumentException('Subset source must not contain duplicates');
                }

                $scalarKeys[$key] = true;

                continue;
            }

            if (in_array($value, $other, strict: true)) {
                throw new \InvalidArgumentException('Subset source must not contain duplicates');
            }

            $other = [...$other, $value];
        }
    }
}
