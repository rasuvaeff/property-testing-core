<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\Clock;
use Rasuvaeff\PropertyTesting\Runner\MonotonicClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MonotonicClock::class)]
final class MonotonicClockTest
{
    public function isTheDefaultClockAndNeverRunsBackwards(): void
    {
        $clock = new MonotonicClock();
        Assert::instanceOf($clock, Clock::class);

        $first = $clock->nanoseconds();
        usleep(1_000);
        $second = $clock->nanoseconds();

        Assert::true($second >= $first + 1_000_000, sprintf('%d ns elapsed across a 1 ms sleep', $second - $first));
    }
}
