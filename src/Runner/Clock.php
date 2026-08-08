<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * Monotonic time source behind the per-run deadline and the phase budget.
 *
 * The seam exists so deadline/budget behaviour can be characterised
 * deterministically (a fake clock advances by exact amounts) instead of by
 * sleeping; production code always uses {@see MonotonicClock}.
 *
 * @api
 */
interface Clock
{
    /**
     * Current reading of a monotonic clock, in nanoseconds. Only differences
     * between two readings are meaningful.
     */
    public function nanoseconds(): int;
}
