<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\FloatArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(FloatArbitrary::class)]
final class FloatArbitraryTest
{
    public function generateStaysWithinRange(): void
    {
        $arbitrary = new FloatArbitrary(1.5, 3.5);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true($value >= 1.5 && $value <= 3.5);
        }
    }

    public function shrinkTriesZero(): void
    {
        $node = Trees::generateWhere(new FloatArbitrary(), static fn(mixed $v): bool => $v !== 0.0);

        Assert::same(Trees::childValues($node), [0.0]);
    }

    public function shrinkTargetsNearestBoundWhenRangeExcludesZero(): void
    {
        // floatBetween(5, 10) must shrink toward the in-range bound (5.0), not 0.0.
        $node = Trees::generateWhere(new FloatArbitrary(5.0, 10.0), static fn(mixed $v): bool => $v !== 5.0);

        Assert::same(Trees::childValues($node), [5.0]);
    }

    public function shrinkOfZeroYieldsNothing(): void
    {
        $node = Trees::generateWhere(new FloatArbitrary(), static fn(mixed $v): bool => $v === 0.0);

        Assert::same(Trees::childValues($node), []);
    }

    public function shrinkTargetIsTerminal(): void
    {
        // The single candidate is a leaf: descending offers nothing further.
        $node = Trees::generateWhere(new FloatArbitrary(), static fn(mixed $v): bool => $v !== 0.0);

        foreach ($node->shrinks() as $child) {
            Assert::same(Trees::childValues($child), []);
        }
    }

    public function acceptsAndGeneratesADegenerateRange(): void
    {
        // min === max is a valid single-point range and must construct.
        $arbitrary = new FloatArbitrary(2.0, 2.0);

        Assert::same($arbitrary->generate(new Random(1))->value, 2.0);
    }

    public function generateBiasesTowardZero(): void
    {
        // Uniform [0, 1) hits exactly 0.0 with probability ~0; the bias makes it
        // frequent.
        $arbitrary = new FloatArbitrary(0.0, 1.0);
        $random = new Random(1);
        $zeroHits = 0;

        for ($i = 0; $i < 1000; ++$i) {
            if ($arbitrary->generate($random)->value === 0.0) {
                ++$zeroHits;
            }
        }

        // ~1 draw in 5 is the boundary 0.0 (~200 of 1000); the band also rules
        // out an inverted condition that would bias ~4 in 5.
        Assert::true($zeroHits > 100 && $zeroHits < 400);
    }

    public function generateNeverEmitsTheExclusiveUpperBound(): void
    {
        $arbitrary = new FloatArbitrary(0.0, 1.0);
        $random = new Random(1);

        for ($i = 0; $i < 1000; ++$i) {
            Assert::true($arbitrary->generate($random)->value < 1.0);
        }
    }

    public function aSpanOfAFewUlpsStaysBelowTheExclusiveUpperBound(): void
    {
        // ulp(1e16) is 2: for a fraction of 0.5 and above, min + fraction * span
        // rounds up to max itself. The domain is [min, max), so that draw
        // lands on min instead.
        $arbitrary = new FloatArbitrary(1e16, 1e16 + 2.0);
        $random = new Random(3);
        $sawMin = false;

        for ($i = 0; $i < 500; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true($value >= 1e16);
            Assert::true($value < 1e16 + 2.0);
            $sawMin = $sawMin || $value === 1e16;
        }

        Assert::true($sawMin);
    }

    #[DataProvider('nonFiniteBounds')]
    public function rejectsANonFiniteBound(float $min, float $max): void
    {
        try {
            new FloatArbitrary($min, $max);

            Assert::fail('expected the non-finite bound to be rejected');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Min and max must be finite');
        }
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function nonFiniteBounds(): iterable
    {
        yield 'NAN min' => [NAN, 1.0];
        yield 'NAN max' => [0.0, NAN];
        yield 'INF max' => [0.0, INF];
        yield '-INF min' => [-INF, 0.0];
    }

    public function theUpperBoundGuardDoesNotCollapseDrawsOntoMin(): void
    {
        // The guard only catches a draw that rounded onto max; a span computed
        // wrongly (or a value interpolated past max) would send a large share
        // of draws to min, which the boundary bias alone never does.
        $arbitrary = new FloatArbitrary(2.0, 6.0);
        $random = new Random(9);
        $atMin = 0;
        $upperHalf = 0;

        for ($i = 0; $i < 1000; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true($value >= 2.0 && $value < 6.0);
            $atMin += $value === 2.0 ? 1 : 0;
            $upperHalf += $value >= 4.0 ? 1 : 0;
        }

        Assert::true($atMin < 250);
        Assert::true($upperHalf > 300);
    }

    public function fullFloatRangeReachesBothSignsWithoutCollapsing(): void
    {
        // The endpoint interpolation for an infinite span must actually spread
        // draws across the range, not resolve to one endpoint or to min.
        $arbitrary = new FloatArbitrary(-PHP_FLOAT_MAX, PHP_FLOAT_MAX);
        $random = new Random(11);
        $positive = 0;
        $negative = 0;
        $atMin = 0;

        for ($i = 0; $i < 300; ++$i) {
            $value = $arbitrary->generate($random)->value;

            $positive += $value > 1.0 ? 1 : 0;
            $negative += $value < -1.0 ? 1 : 0;
            $atMin += $value === -PHP_FLOAT_MAX ? 1 : 0;
        }

        Assert::true($positive > 50 && $negative > 50);
        Assert::true($atMin < 75);
    }

    public function generateSequenceIsPinnedForAFixedSeed(): void
    {
        // Pins the exact interleaving of the bias draw (int(1, 5) === 1) with
        // the uniform draws: widening the bias range to start at 0 or moving
        // the comparison to another constant reshuffles which draws take the
        // boundary branch, so the whole sequence diverges.
        $arbitrary = new FloatArbitrary(0.0, 1.0);
        $random = new Random(1);
        $values = [];

        for ($i = 0; $i < 12; ++$i) {
            $values[] = $arbitrary->generate($random)->value;
        }

        Assert::same($values, [
            0.0,
            0.7657471024716561,
            0.9650243271195564,
            0.8905562228732221,
            0.5588039463709776,
            0.42022387039850206,
            0.7552392297664966,
            0.0,
            0.2817938210734544,
            0.825852929059297,
            0.8825442626886809,
            0.6445506013370492,
        ]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvertedRange(): void
    {
        new FloatArbitrary(10.0, 1.0);
    }

    public function fullFloatRangeNeverProducesNan(): void
    {
        // -PHP_FLOAT_MAX..PHP_FLOAT_MAX overflows the span subtraction to INF,
        // and INF * 0.0 (a 0.0 uniform draw) would generate NAN.
        $arbitrary = new FloatArbitrary(-PHP_FLOAT_MAX, PHP_FLOAT_MAX);
        $random = new Random(7);

        for ($i = 0; $i < 300; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::false(is_nan($value));
            Assert::true(is_finite($value));
        }
    }
}
