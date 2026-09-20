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
    ) {}
}
