<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * The default {@see Clock}: `hrtime()`, monotonic and immune to a wall-clock
 * adjustment mid-run.
 *
 * Deadlines and time budgets measure elapsed time, and a clock that can step
 * backwards would report a negative one. PSR-20 is deliberately not used here:
 * it answers "what time is it" in `DateTimeImmutable`, which is the wall clock
 * this class exists to avoid.
 *
 * @api
 */
final readonly class MonotonicClock implements Clock
{
    #[\Override]
    public function nanoseconds(): int
    {
        return hrtime(as_number: true);
    }
}
