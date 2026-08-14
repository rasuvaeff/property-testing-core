<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * What the random phase actually generated, as data: every
 * {@see \Rasuvaeff\PropertyTesting\Classify} label with its share, every
 * `cover()` threshold beside the share it is compared against, and the discard
 * tally with its own share.
 *
 * The engine has always counted these; until now it handed over raw counters
 * for an adapter to format into a line of text, which left everything that is
 * not a human parsing that line back — a CI job collecting distributions, or a
 * test asserting that a property really reaches a branch often enough. This is
 * that line's contents before it becomes a line. Printing remains the adapter's
 * job.
 *
 * A projection of counters already accumulated, computed once when the run
 * finishes: `Classify::label()` runs in the property body on every run, this
 * does not.
 *
 * Two denominators, deliberately different and named so a consumer cannot mix
 * them up: label shares are over the successful {@see $checks}, the discard
 * share is over the {@see $attempts}.
 *
 * @api
 */
final readonly class DistributionReport
{
    /**
     * @param int $attempts Bodies executed in the random phase, discarded ones included.
     * @param int $discards Runs discarded via `Assume::that()`.
     * @param int $checks Successful (non-discarded, non-failing) runs — the label denominator.
     * @param list<LabelShare> $labels Every label recorded or required, most frequent first and
     *        alphabetical within a count, so two runs of the same property compare line by line.
     * @param bool $coverageAssessed Whether the engine judged the `cover()` requirements. False when
     *        the run ended before the check loop completed (it gave up on discards, or ran out of
     *        its time budget): the shares below are still what happened, but nothing enforced them.
     */
    public function __construct(
        public int $attempts,
        public int $discards,
        public int $checks,
        public array $labels,
        public bool $coverageAssessed,
    ) {}

    /**
     * The report for one set of counters.
     *
     * @param RunStatistics $statistics The phase's accumulated counters and `cover()` requirements.
     * @param bool $coverageAssessed Whether the run reached the coverage assessment at all.
     */
    public static function of(RunStatistics $statistics, bool $coverageAssessed): self
    {
        $labels = [];

        foreach (self::labelNames($statistics) as $label) {
            $count = $statistics->classifications[$label] ?? 0;

            $labels[] = new LabelShare(
                label: $label,
                count: $count,
                percent: self::percent($count, $statistics->checks),
                required: $statistics->requirements[$label] ?? null,
            );
        }

        usort(
            $labels,
            static fn(LabelShare $a, LabelShare $b): int => [$b->count, $a->label] <=> [$a->count, $b->label],
        );

        return new self(
            attempts: $statistics->attempts,
            discards: $statistics->discards,
            checks: $statistics->checks,
            labels: $labels,
            coverageAssessed: $coverageAssessed,
        );
    }

    /**
     * Discarded runs as a percentage of the attempts — the one share whose
     * denominator is not {@see $checks}, because a discard is precisely an
     * attempt that never became a check. A phase that executed nothing reports
     * 0.0 rather than a division by zero.
     */
    public function discardPercent(): float
    {
        return self::percent($this->discards, $this->attempts);
    }

    /**
     * One label's share, or null when the property never recorded or required
     * it — which is the answer to "is this branch reached at all".
     *
     * @param string $label The label as the property body would record it.
     */
    public function label(string $label): ?LabelShare
    {
        foreach ($this->labels as $share) {
            if ($share->label === $label) {
                return $share;
            }
        }

        return null;
    }

    /**
     * The labels whose `cover()` threshold the recorded share does not reach.
     * Empty when every requirement held — and, on a run that never reached the
     * assessment, still only arithmetic (see {@see $coverageAssessed}).
     *
     * @return list<LabelShare>
     */
    public function unmetRequirements(): array
    {
        return array_values(array_filter(
            $this->labels,
            static fn(LabelShare $share): bool => !$share->meetsRequirement(),
        ));
    }

    /**
     * Machine-readable representation suitable for telemetry and serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attempts' => $this->attempts,
            'discards' => $this->discards,
            'discardPercent' => $this->discardPercent(),
            'checks' => $this->checks,
            'coverageAssessed' => $this->coverageAssessed,
            'labels' => array_map(
                static fn(LabelShare $share): array => [
                    'label' => $share->label,
                    'count' => $share->count,
                    'percent' => $share->percent,
                    'required' => $share->required,
                    'met' => $share->meetsRequirement(),
                ],
                $this->labels,
            ),
        ];
    }

    /**
     * Every label the property either recorded or required. A required label
     * with no occurrences belongs in the report: zero coverage of a demanded
     * case is the finding, and leaving it out would hide it.
     *
     * @return list<string>
     */
    private static function labelNames(RunStatistics $statistics): array
    {
        // Union by key, not concatenation: `+` keeps one entry per label and
        // array_keys() hands back a list, so neither uniqueness nor listness
        // needs a second pass to restore it.
        //
        // The cast restores what PHP took away: a label like '42' is a numeric
        // string, so it was stored under an integer key and comes back as an
        // int. LabelShare declares a string label, and strict_types would throw
        // on the way in.
        return array_map(
            static fn(int|string $label): string => (string) $label,
            array_keys($statistics->classifications + $statistics->requirements),
        );
    }

    private static function percent(int $count, int $total): float
    {
        // fdiv() rather than a cast-laden `/`: psalm's strict binary operands
        // would demand two casts that no behaviour could distinguish from their
        // absence, and an untestable line is worse than an explicit function.
        return $total === 0 ? 0.0 : fdiv($count * 100, $total);
    }
}
