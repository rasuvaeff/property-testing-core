<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Internal\Boundary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates floats in the half-open range [min, max).
 *
 * Generation is biased: roughly one draw in {@see BIAS_DENOMINATOR} returns an
 * in-range boundary value (0.0 or min) instead of a uniform one, because bugs
 * cluster at edges. The exclusive upper bound is never emitted.
 *
 * Shrinking floats reliably is hard (no natural "smallest" value), so the
 * shrink tree has a single candidate: zero, clamped into the configured range.
 * For fine-grained shrinking on a numeric input, generate an integer and
 * {@see \Rasuvaeff\PropertyTesting\Gen::map()} it to a float — with integrated
 * shrinking the mapped value shrinks through the integer's tree.
 *
 * @implements ArbitraryInterface<float>
 * @api
 */
final readonly class FloatArbitrary implements ArbitraryInterface
{
    private const int BIAS_DENOMINATOR = 5;

    public function __construct(
        private float $min = 0.0,
        private float $max = 1.0,
    ) {
        if ($min > $max) {
            throw new \InvalidArgumentException('Min must be less than or equal to max');
        }
    }

    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $boundaries = Boundary::floats($this->min, $this->max);

        if ($boundaries !== [] && $random->int(1, self::BIAS_DENOMINATOR) === 1) {
            return $this->tree($boundaries[$random->int(0, count($boundaries) - 1)]);
        }

        return $this->tree($this->min + $random->float() * ($this->max - $this->min));
    }

    /** @return Shrinkable<float> */
    private function tree(float $value): Shrinkable
    {
        return Shrinkable::of($value, function () use ($value): \Generator {
            // Shrink toward zero, clamped to the configured range so the candidate
            // stays in the generated domain (mirrors IntArbitrary). For a range
            // that excludes zero, e.g. [5.0, 10.0], the target is the nearest
            // bound (5.0).
            $target = max($this->min, min($this->max, 0.0));

            if ($value !== $target) {
                yield Shrinkable::leaf($target);
            }
        });
    }
}
