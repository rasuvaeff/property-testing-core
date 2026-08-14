<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * Counters of a random phase, carried by the results whose reporting needs
 * them: the classification distribution and the excessive-discard warning are
 * printed by the adapter (the engine never formats framework output), so the
 * result hands over the raw numbers.
 *
 * @api
 */
final readonly class RunStatistics
{
    /**
     * @param int $attempts Bodies executed in the random phase, discarded ones included.
     * @param int $discards Runs discarded via `Assume::that()`.
     * @param int $checks Successful (non-discarded, non-failing) runs completed.
     * @param array<string, int> $classifications Per-label counts from `Classify` over the passing runs.
     * @param array<string, float> $requirements Minimum percentages `Classify::cover()` registered,
     *        by label — carried alongside the counts they are compared against, including at the
     *        exits that never reached the assessment, so a report can say what was demanded as well
     *        as what happened.
     */
    public function __construct(
        public int $attempts,
        public int $discards,
        public int $checks,
        public array $classifications,
        public array $requirements = [],
    ) {}
}
