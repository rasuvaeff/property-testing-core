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
     * @param int $discards Runs discarded via `Assume::that()` — the input left the domain.
     * @param int $checks Successful (non-discarded, non-failing) runs completed.
     * @param array<array-key, int> $classifications Per-label counts from `Classify` over the passing
     *        runs. Keyed by label — as `array-key` rather than `string` because PHP stores a numeric
     *        label such as `'42'` under an integer key, and a type that denied it would be a lie the
     *        readers of this array pay for.
     * @param array<array-key, float> $requirements Minimum percentages `Classify::cover()` registered,
     *        by label — carried alongside the counts they are compared against, including at the
     *        exits that never reached the assessment, so a report can say what was demanded as well
     *        as what happened.
     * @param int $skips Runs the environment refused (a missing dependency, a skipped lifecycle
     *        hook). Counted apart from the discards because they say nothing about the generators,
     *        and a report that folded them in would advise narrowing generators that are not at
     *        fault.
     * @param array<string, array<array-key, int>> $tables Per-table, per-tag counts from
     *        `Classify::tabulate()` over the passing runs; `array-key` for the reason
     *        `$classifications` gives.
     * @param array<string, array<string, int>> $intersections Per table, how many passing runs hit
     *        each pair of its tags together, keyed `tagA & tagB` with the two tags in sorted order.
     * @param ?int $domainSize The size of the parameter domain the phase enumerated instead of
     *        sampling ({@see PropertyConfig::$exhaustive}); null when it sampled.
     * @param ?string $exhaustiveDeclined Why an exhaustive run sampled after all — a parameter
     *        without a finite domain, or a domain above the budget; null when it enumerated or
     *        was never asked to.
     * @param ?SearchReport $search What the targeted search amounted to; null when the property
     *        targeted nothing.
     * @param int $discardedExamples Explicit examples (`<method>Examples()`) the body discarded with
     *        `Assume::that()`. Such an example counts as passed — the run's verdict does not change —
     *        but it checked nothing: after a precondition moves, a pinned case can silently stop
     *        pinning anything, and this is the number the adapters warn about.
     */
    public function __construct(
        public int $attempts,
        public int $discards,
        public int $checks,
        public array $classifications,
        public array $requirements = [],
        public int $skips = 0,
        public array $tables = [],
        public array $intersections = [],
        public ?int $domainSize = null,
        public ?string $exhaustiveDeclined = null,
        public ?SearchReport $search = null,
        public int $discardedExamples = 0,
    ) {}

    /**
     * The statistics a result carries, for the outcomes that carry them —
     * {@see Passed}, {@see GaveUp}, {@see CoverageFailed} and
     * {@see TimeBudgetExceeded} — and null for every other one. The adapters'
     * diagnostics are built from these; a result type that gains statistics is
     * added here, not to the {@see PropertyResult} interface, which outside
     * implementations would have to follow.
     *
     * @param PropertyResult $result Any outcome of a run.
     */
    public static function of(PropertyResult $result): ?self
    {
        return match (true) {
            $result instanceof Passed,
            $result instanceof GaveUp,
            $result instanceof CoverageFailed,
            $result instanceof TimeBudgetExceeded => $result->statistics,
            default => null,
        };
    }
}
