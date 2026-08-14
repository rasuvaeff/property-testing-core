<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\DistributionReport;
use Rasuvaeff\PropertyTesting\Runner\LabelShare;
use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
use Rasuvaeff\PropertyTesting\Tests\Support\Check;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The report is a projection of counters the phase already accumulated, so
 * these tests feed it counters directly. What it must never do is invent a
 * denominator: shares are over the checks, the discard share is over the
 * attempts, and a phase that executed nothing divides by neither.
 */
#[Test]
#[Covers(DistributionReport::class)]
final class DistributionReportTest
{
    public function labelSharesAreComputedOverTheChecks(): void
    {
        // Twelve attempts, eight of them discarded: the four checks are the
        // denominator, so "even" is 50% and not 16.7%.
        $report = DistributionReport::of(
            new RunStatistics(attempts: 12, discards: 8, checks: 4, classifications: ['even' => 2, 'odd' => 2]),
            coverageAssessed: true,
        );

        Assert::same($report->label('even')?->percent, 50.0);
        Assert::same($report->label('even')?->count, 2);
        Assert::same($report->checks, 4);
    }

    public function theDiscardShareIsTheOneMeasuredOverTheAttempts(): void
    {
        $report = DistributionReport::of(
            new RunStatistics(attempts: 12, discards: 9, checks: 3, classifications: []),
            coverageAssessed: true,
        );

        Assert::same($report->discardPercent(), 75.0);
    }

    public function aPhaseThatExecutedNothingReportsZeroesRatherThanADivision(): void
    {
        // The zero-phase run (`phases` without Random) reports honest zeros;
        // 0/0 must be 0.0 here, not NAN — a NAN travels silently through
        // telemetry and comparisons.
        $report = DistributionReport::of(
            new RunStatistics(attempts: 0, discards: 0, checks: 0, classifications: [], requirements: ['never' => 5.0]),
            coverageAssessed: false,
        );

        Assert::same($report->discardPercent(), 0.0);
        Assert::same($report->label('never')?->percent, 0.0);
        Assert::false(is_nan($report->discardPercent()));
    }

    public function aRunWithoutLabelsReportsAnEmptyListRatherThanNull(): void
    {
        $report = DistributionReport::of(
            new RunStatistics(attempts: 5, discards: 0, checks: 5, classifications: []),
            coverageAssessed: true,
        );

        Assert::same($report->labels, []);
        Assert::null($report->label('anything'));
        Assert::same($report->unmetRequirements(), []);
    }

    public function aRequiredLabelTheRunsNeverReachedStillAppears(): void
    {
        // The whole point of reporting requirements: zero coverage of a
        // demanded case is the finding, and omitting the label would hide it.
        $report = DistributionReport::of(
            new RunStatistics(
                attempts: 10,
                discards: 0,
                checks: 10,
                classifications: ['common' => 10],
                requirements: ['rare' => 5.0],
            ),
            coverageAssessed: true,
        );

        Assert::same($report->label('rare')?->count, 0);
        Assert::same($report->label('rare')?->required, 5.0);
        Assert::false($report->label('rare')?->meetsRequirement() ?? true);
        Assert::same(
            array_map(static fn(LabelShare $share): string => $share->label, $report->unmetRequirements()),
            ['rare'],
        );
    }

    public function labelsAreOrderedByCountAndThenAlphabetically(): void
    {
        // Deterministic order, so two runs of the same property compare line by
        // line — insertion order would depend on which input came first.
        $report = DistributionReport::of(
            new RunStatistics(
                attempts: 9,
                discards: 0,
                checks: 9,
                classifications: ['beta' => 2, 'alpha' => 2, 'gamma' => 5],
            ),
            coverageAssessed: true,
        );

        Assert::same(
            array_map(static fn(LabelShare $share): string => $share->label, $report->labels),
            ['gamma', 'alpha', 'beta'],
        );
    }

    public function theMachineReadableFormCarriesEveryFactTheReportHas(): void
    {
        $report = DistributionReport::of(
            new RunStatistics(
                attempts: 4,
                discards: 2,
                checks: 2,
                classifications: ['hit' => 1],
                requirements: ['hit' => 75.0],
            ),
            coverageAssessed: false,
        );

        Assert::same($report->toArray(), [
            'attempts' => 4,
            'discards' => 2,
            'discardPercent' => 50.0,
            'checks' => 2,
            'coverageAssessed' => false,
            'labels' => [
                ['label' => 'hit', 'count' => 1, 'percent' => 50.0, 'required' => 75.0, 'met' => false],
            ],
        ]);
    }

    /**
     * Whatever the counters, a share is a percentage: never negative, never
     * above a hundred, and never a NAN that would poison a telemetry pipeline
     * or a comparison downstream.
     */
    public function everyReportedShareIsAPercentage(): void
    {
        Check::property(
            static function (array $classifications, int $checks, int $discards): void {
                $report = DistributionReport::of(
                    new RunStatistics(
                        attempts: $checks + $discards,
                        discards: $discards,
                        checks: $checks,
                        // Counts cannot exceed the checks: a label is recorded
                        // at most once per successful run.
                        classifications: array_map(
                            static fn(int $count): int => $checks === 0 ? 0 : $count % ($checks + 1),
                            $classifications,
                        ),
                    ),
                    coverageAssessed: true,
                );

                foreach ([...array_map(static fn(LabelShare $share): float => $share->percent, $report->labels), $report->discardPercent()] as $percent) {
                    Assert::false(is_nan($percent));
                    Assert::true($percent >= 0.0 && $percent <= 100.0);
                }
            },
            [
                'classifications' => Gen::dictOf(Gen::stringOf(1, 6), Gen::intBetween(0, 500), maxSize: 6),
                'checks' => Gen::intBetween(0, 500),
                'discards' => Gen::intBetween(0, 500),
            ],
            runs: 200,
            seed: 20_260_815,
        );
    }
}
