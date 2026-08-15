<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Random::class)]
final class RandomTest
{
    public function sameSeedProducesIdenticalSequence(): void
    {
        $a = new Random(123);
        $b = new Random(123);

        $sequenceA = $this->snapshot($a);
        $sequenceB = $this->snapshot($b);

        Assert::same($sequenceA, $sequenceB);
    }

    public function intStaysWithinInclusiveRange(): void
    {
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $value = $random->int(5, 10);

            Assert::true($value >= 5 && $value <= 10);
        }
    }

    public function floatStaysInHalfOpenUnitRange(): void
    {
        $random = new Random(7);

        for ($i = 0; $i < 200; ++$i) {
            $value = $random->float();

            Assert::true($value >= 0.0 && $value < 1.0);
        }
    }

    public function floatProducesVaryingValuesAcrossTheUnitRange(): void
    {
        $random = new Random(7);
        $sawLow = false;
        $sawHigh = false;

        for ($i = 0; $i < 200; ++$i) {
            $value = $random->float();

            $value < 0.5 ? $sawLow = true : $sawHigh = true;
        }

        Assert::true($sawLow);
        Assert::true($sawHigh);
    }

    public function bytesReturnsStringOfRequestedLength(): void
    {
        $random = new Random(3);

        Assert::same(strlen($random->bytes(0)), 0);
        Assert::same(strlen($random->bytes(16)), 16);
    }

    public function differentSeedsDivergeOnFirstDraw(): void
    {
        $first = (new Random(1))->int(0, PHP_INT_MAX);
        $second = (new Random(2))->int(0, PHP_INT_MAX);

        Assert::false($first === $second);
    }

    public function whichDrawsAreEdgeCasesIsPinnedForASeed(): void
    {
        // A golden pattern, not a statistic: which draws become edge values is
        // part of what a seed means. Change the roll — its range, or which
        // result counts — and the same seed generates a different sequence,
        // which is the one thing SEQUENCE_EPOCH exists to fence off. A
        // frequency test cannot see that; this can.
        $random = new Random(7);
        $pattern = '';

        for ($i = 0; $i < 20; ++$i) {
            $pattern .= $random->drawsEdgeCase(5) ? '1' : '0';
        }

        Assert::same($pattern, '10000000000000010000');
    }

    public function edgeCasesAreDrawnAboutOneTimeInTheDenominator(): void
    {
        $random = new Random(7);
        $edges = 0;

        for ($i = 0; $i < 1_000; ++$i) {
            if ($random->drawsEdgeCase(5)) {
                ++$edges;
            }
        }

        // One in five, with room for the noise of a thousand rolls.
        Assert::true($edges > 120 && $edges < 280);
    }

    public function noneNeverDrawsAnEdgeCase(): void
    {
        $random = new Random(7, EdgeCases::None);

        for ($i = 0; $i < 200; ++$i) {
            Assert::false($random->drawsEdgeCase(5));
        }
    }

    public function bothModesStayAlignedOnTheSameSeed(): void
    {
        // The design claim of EdgeCases::None, asserted: the roll is consumed
        // either way, so turning edge cases off changes which values are edges
        // and not every draw after the first one. Skipping the roll instead
        // would make the same seed mean something else in the two modes.
        $mixin = new Random(11);
        $none = new Random(11, EdgeCases::None);

        for ($i = 0; $i < 50; ++$i) {
            $mixin->drawsEdgeCase(5);
            $none->drawsEdgeCase(5);
        }

        Assert::same($mixin->int(0, 1_000_000), $none->int(0, 1_000_000));
    }

    /**
     * @return list<mixed>
     */
    private function snapshot(Random $random): array
    {
        return [
            $random->int(0, 1000),
            $random->int(0, 1000),
            $random->float(),
            $random->bytes(4),
        ];
    }
}
