<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Random\Engine\Mt19937;
use Random\IntervalBoundary;
use Random\Randomizer;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
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

    public function floatMatchesNativeGetFloatInterleavedWithOtherDraws(): void
    {
        if (!method_exists(Randomizer::class, 'getFloat')) {
            throw new SkipTest('Randomizer::getFloat() needs PHP 8.3');
        }

        for ($seed = 0; $seed < 300; ++$seed) {
            $random = new Random($seed);
            $native = new Randomizer(new Mt19937($seed));

            for ($i = 0; $i < 40; ++$i) {
                Assert::same($random->int(-1_000, 1_000), $native->getInt(-1_000, 1_000));
                Assert::same($random->float(), $native->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen));
                Assert::same($random->bytes(3), $native->getBytes(3));
            }
        }
    }

    /**
     * The digest was taken from PHP 8.5's native `getInt()`/`getFloat()`/`getBytes()`
     * on the same seeds, so on PHP 8.2 this is the check that the stream is unchanged.
     */
    public function mixedDrawStreamMatchesPinnedDigest(): void
    {
        $stream = '';
        foreach ([0, 1, 42, 2026, 2_147_483_647] as $seed) {
            $random = new Random($seed);

            for ($i = 0; $i < 50; ++$i) {
                $stream .= $random->int(-1_000, 1_000)
                    . '|' . bin2hex(pack('E', $random->float()))
                    . '|' . bin2hex($random->bytes(3)) . "\n";
            }
        }

        Assert::same(
            hash('sha256', $stream),
            '5258def6244ca79df1887eaddacde54e18d3022ab73369a9e3a10a793e36b168',
        );
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
