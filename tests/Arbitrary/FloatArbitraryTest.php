<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\FloatArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
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
