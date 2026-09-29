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
    /**
     * The floor a sleep is measured against, generously below what is slept:
     * what deadlines and budgets need from this clock is that it advances with
     * the wall clock, not that a sleep is punctual. A 1 ms sleep against a 1 ms
     * floor was the same assertion with no slack at all, and it fails on
     * Windows, where the timer granularity rounds the sleep and `hrtime()` then
     * reports fewer nanoseconds than were asked for (seen on CI 2026-09-28).
     */
    private const int FLOOR_NS = 1_000_000;

    private const int SLEEP_US = 20_000;

    public function isTheDefaultClock(): void
    {
        Assert::instanceOf(new MonotonicClock(), Clock::class);
    }

    public function advancesWithTheWallClock(): void
    {
        $clock = new MonotonicClock();

        $first = $clock->nanoseconds();
        usleep(self::SLEEP_US);
        $second = $clock->nanoseconds();

        Assert::true(
            $second - $first >= self::FLOOR_NS,
            sprintf('%d ns elapsed across a %d us sleep', $second - $first, self::SLEEP_US),
        );
    }

    public function neverRunsBackwards(): void
    {
        // What the name always promised and the sleep never checked: adjacent
        // reads, with nothing between them, are non-decreasing. A clock that
        // stepped back would make an elapsed time negative — the one thing this
        // class exists to rule out — and a single read cannot show it.
        $clock = new MonotonicClock();
        $readings = [];

        for ($read = 0; $read < 1_000; ++$read) {
            $readings[] = $clock->nanoseconds();
        }

        $ascending = $readings;
        sort($ascending);

        Assert::same($readings, $ascending);
    }
}
