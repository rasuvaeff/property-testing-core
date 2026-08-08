<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Thrown (as the failure of a property) when the random phase's wall-clock
 * time exceeds the {@see Property::$budgetMs} budget before the requested
 * number of successful checks completes. It exposes the completed and required
 * run counts so a slow property cannot silently check less than it claims.
 *
 * The fix is to raise the budget, lower the run count, or speed up the
 * property body (often by narrowing the generators).
 *
 * @api
 */
final class TimeBudgetExceededException extends RuntimeException
{
    /**
     * @param int $budgetMs The configured whole-phase budget.
     * @param float $elapsedMs Measured wall-clock duration of the phase so far.
     */
    public function __construct(
        public readonly string $propertyName,
        public readonly int $budgetMs,
        public readonly float $elapsedMs,
        public readonly int $successfulRuns,
        public readonly int $requiredRuns,
    ) {
        parent::__construct(sprintf(
            'Property "%s" exceeded its %d ms time budget after %.1f ms with %d/%d successful run(s). '
            . 'Raise budgetMs, lower runs, or speed up the property body.',
            $propertyName,
            $budgetMs,
            $elapsedMs,
            $successfulRuns,
            $requiredRuns,
        ));
    }
}
