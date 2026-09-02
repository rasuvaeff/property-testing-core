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
        if (!is_finite($min) || !is_finite($max)) {
            throw new \InvalidArgumentException('Min and max must be finite');
        }
        if ($min > $max) {
            throw new \InvalidArgumentException('Min must be less than or equal to max');
        }
    }

    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $boundaries = Boundary::floats($this->min, $this->max);

        if ($boundaries !== [] && $random->drawsEdgeCase(self::BIAS_DENOMINATOR)) {
            return $this->tree($boundaries[$random->int(0, count($boundaries) - 1)]);
        }

        $span = $this->max - $this->min;
        $fraction = $random->float();

        if (!is_finite($span)) {
            // A range wider than a float can hold (e.g. -PHP_FLOAT_MAX..PHP_FLOAT_MAX)
            // overflows the subtraction to INF, and INF * 0.0 would hand the
            // property a NAN; interpolate the two endpoints separately instead.
            return $this->tree($this->min * (1.0 - $fraction) + $this->max * $fraction);
        }

        return $this->tree($this->withinRange($this->min + $fraction * $span));
    }

    /**
     * Keeps an interpolated value below the exclusive upper bound. When the
     * span is within a few ulps of `min` (e.g. `1e16 .. 1e16 + 2`), the
     * addition rounds up to `max` itself for a large enough fraction; the
     * generated domain is `[min, max)`, so such a draw lands on `min`
     * instead. A degenerate range (`min === max`) has that one value only.
     */
    private function withinRange(float $value): float
    {
        return $value >= $this->max && $this->min < $this->max ? $this->min : $value;
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
