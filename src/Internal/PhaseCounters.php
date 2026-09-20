<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
use Rasuvaeff\PropertyTesting\Runner\SearchReport;

/**
 * The counters one phase of the runner accumulates across its attempts —
 * the random walk and the search that may follow it share one set, so a
 * result carries the whole phase — and the {@see RunStatistics} they become.
 *
 * @internal Driven by the property runner.
 */
final class PhaseCounters
{
    public int $attempts = 0;

    public int $discards = 0;

    public int $skips = 0;

    public int $checks = 0;

    /** @var array<array-key, int> */
    public array $classifications = [];

    /** @var array<string, array<array-key, int>> */
    public array $tables = [];

    /** @var array<string, array<string, int>> */
    public array $intersections = [];

    /**
     * @param ?int $domainSize The enumerated domain's size, when the phase walks instead of samples.
     * @param ?string $exhaustiveDeclined Why an exhaustive run samples after all; null otherwise.
     */
    public function __construct(
        private readonly ?int $domainSize,
        private readonly ?string $exhaustiveDeclined,
    ) {}

    /**
     * Account for a passing run.
     *
     * @param list<string> $labels The labels the run recorded.
     * @param array<string, list<string>> $tabulated The tables the run recorded, tags by table.
     */
    public function passed(array $labels, array $tabulated): void
    {
        foreach ($labels as $label) {
            $this->classifications[$label] = ($this->classifications[$label] ?? 0) + 1;
        }

        foreach ($tabulated as $table => $tags) {
            foreach ($tags as $tag) {
                $this->tables[$table][$tag] = ($this->tables[$table][$tag] ?? 0) + 1;
            }

            // Every pair of tags hit together, in sorted order so `a & b`
            // and `b & a` are one key.
            sort($tags, SORT_STRING);
            $count = count($tags);

            for ($i = 0; $i < $count; ++$i) {
                for ($j = $i + 1; $j < $count; ++$j) {
                    $pair = $tags[$i] . ' & ' . $tags[$j];
                    $this->intersections[$table][$pair] = ($this->intersections[$table][$pair] ?? 0) + 1;
                }
            }
        }

        ++$this->checks;
    }

    /**
     * The counters as the statistics a result carries.
     *
     * @param array<array-key, float> $requirements The `cover()` thresholds, by label.
     * @param ?SearchReport $search What the search phase amounted to, when one ran.
     */
    public function statistics(array $requirements, ?SearchReport $search = null): RunStatistics
    {
        return new RunStatistics(
            $this->attempts,
            $this->discards,
            $this->checks,
            $this->classifications,
            $requirements,
            $this->skips,
            $this->tables,
            $this->intersections,
            $this->domainSize,
            $this->exhaustiveDeclined,
            $search,
        );
    }
}
