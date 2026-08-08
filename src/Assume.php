<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * Discard a property run when a precondition does not hold.
 *
 * An attempt discarded via {@see Assume::that()} is neither a failure nor a
 * successful check: the property runner retries with another random input. Use
 * it to skip combinations of generated values that are out of the
 * property's domain (e.g. "cap must be >= baseSeconds") instead of rejecting
 * them with a narrow {@see Gen::filter()}, which is slower.
 *
 * Discards do not consume {@see Property::$runs}. The runner warns when more
 * than 90% of attempts are discarded and fails with {@see GaveUpException} when
 * {@see Property::$maxDiscards} is exceeded.
 *
 * @api
 */
final class Assume
{
    /**
     * Discard the current run unless the condition is true.
     */
    public static function that(bool $condition): void
    {
        if (!$condition) {
            throw new AssumptionSkipped();
        }
    }
}
