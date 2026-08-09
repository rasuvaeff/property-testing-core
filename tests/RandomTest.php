<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Random;
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
