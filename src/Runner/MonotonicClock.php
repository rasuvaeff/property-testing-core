<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
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
