<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\Enumerable;
use Rasuvaeff\PropertyTesting\Internal\Boundary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates integers within an inclusive range and shrinks them toward zero
 * (clamped into the range, so the target of a zero-free range is its nearest
 * bound).
 *
 * Generation is biased: roughly one draw in {@see BIAS_DENOMINATOR} returns an
 * in-range boundary value (0, ±1, min, max) instead of a uniform one, because
 * bugs cluster at edges.
 *
 * The shrink tree halves the distance to the target: the target itself first,
 * then candidates progressively closer to the failing value, each with its own
 * subtree toward the same target — a binary search for the minimal failing
 * integer.
 *
 * @implements Enumerable<int>
 * @api
 */
final readonly class IntArbitrary implements Enumerable
{
    private const int BIAS_DENOMINATOR = 5;

    public function __construct(
        private int $min = PHP_INT_MIN,
        private int $max = PHP_INT_MAX,
    ) {
        if ($min > $max) {
            throw new \InvalidArgumentException('Min must be less than or equal to max');
        }
    }

    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        // Boundary candidates always include min and max, so the list is non-empty.
        if ($random->drawsEdgeCase(self::BIAS_DENOMINATOR)) {
            $boundaries = Boundary::ints($this->min, $this->max);

            return $this->tree($boundaries[$random->int(0, count($boundaries) - 1)]);
        }

        return $this->tree($random->int($this->min, $this->max));
    }

    /**
     * Saturates: a range wider than `PHP_INT_MAX` values (the full int range
     * above all) reports `PHP_INT_MAX`, which no budget accepts.
     */
    #[\Override]
    public function domainSize(): ?int
    {
        // Subtracting first can overflow to a float for the full range; the
        // comparison catches both that and an exact PHP_INT_MAX-wide span.
        $width = $this->max - $this->min;

        return is_int($width) && $width < PHP_INT_MAX ? max(1, $width + 1) : PHP_INT_MAX;
    }

    /**
     * Ascending from the minimum.
     */
    #[\Override]
    public function enumerate(): iterable
    {
        for ($value = $this->min; ; ++$value) {
            yield $this->tree($value);

            if ($value === $this->max) {
                return;
            }
        }
    }

    /** @return Shrinkable<int> */
    private function tree(int $value): Shrinkable
    {
        return Shrinkable::of($value, function () use ($value): \Generator {
            $target = max($this->min, min($this->max, 0));

            if ($value === $target) {
                return;
            }

            yield $this->tree($target);

            // Halve the distance to the target: value - d/2, value - d/4, ...
            // Every candidate lies strictly between target and value, so each
            // level of the tree gets closer and shrinking terminates.
            for ($delta = intdiv($value - $target, 2); $delta !== 0; $delta = intdiv($delta, 2)) {
                yield $this->tree($value - $delta);
            }
        });
    }
}
