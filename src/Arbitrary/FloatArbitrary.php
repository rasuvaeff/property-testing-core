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
 * shrink tree has a single candidate: the point of `[min, max)` nearest to
 * zero — never the excluded upper bound, so for a range at or below zero it
 * is the largest float under `max`.
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
        return $value < $this->max ? $value : $this->min;
    }

    /** @return Shrinkable<float> */
    private function tree(float $value): Shrinkable
    {
        return Shrinkable::of($value, function () use ($value): \Generator {
            $target = $this->shrinkTarget();

            if ($value !== $target) {
                yield Shrinkable::leaf($target);
            }
        });
    }

    /**
     * The point of `[min, max)` nearest to zero — the one candidate every
     * value shrinks to (mirrors IntArbitrary's clamp). `0.0` when the range
     * holds it, `min` when the range lies above zero, and for a range at or
     * below zero the largest float still under `max`: the upper bound itself
     * is excluded from generation, so it must not appear as a counterexample
     * either. A degenerate range (`min === max`) has that one value only.
     */
    private function shrinkTarget(): float
    {
        if ($this->max > 0.0) {
            return max($this->min, 0.0);
        }

        return max($this->min, $this->below($this->max));
    }

    /**
     * The largest double strictly less than $value, for a finite $value at or
     * below zero: PHP has no `nextafter()`, so the IEEE 754 bit pattern is
     * stepped instead. Exact, so the candidate is the same on every machine.
     */
    private function below(float $value): float
    {
        if ($value === 0.0) {
            // Below zero (of either sign) sits the smallest negative subnormal.
            return -5.0e-324;
        }

        /** @var array{1: int} $bits */
        $bits = unpack('J', pack('E', $value));

        // With the sign bit set, the pattern one higher is the double one
        // step further from zero.
        /** @var array{1: float} $stepped */
        $stepped = unpack('E', pack('J', $bits[1] + 1));

        return $stepped[1];
    }
}
