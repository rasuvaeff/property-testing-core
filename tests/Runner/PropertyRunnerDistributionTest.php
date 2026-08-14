<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Event\PropertyFinished;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\CoverageFailed;
use Rasuvaeff\PropertyTesting\Runner\DistributionReport;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\LabelShare;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\TimeBudgetExceeded;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Rasuvaeff\PropertyTesting\Tests\Support\FakeClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The distribution the runner hands to listeners, per outcome. Two facts are
 * pinned throughout: discards never enter the label denominator, and
 * `coverageAssessed` is true only for the outcomes that came out of a completed
 * check loop — the others carry shares nothing enforced.
 */
#[Test]
#[Covers(PropertyRunner::class)]
final class PropertyRunnerDistributionTest
{
    public function aPassReportsEveryLabelWithItsShare(): void
    {
        $report = $this->reportOf(
            static function (int $value): void {
                Classify::label($value % 2 === 0 ? 'even' : 'odd');
            },
            runs: 100,
        );

        Assert::instanceOf($report, DistributionReport::class);
        Assert::same($report->checks, 100);
        Assert::same($report->attempts, 100);
        Assert::same($report->discardPercent(), 0.0);
        Assert::true($report->coverageAssessed);

        $total = array_sum(array_map(static fn(LabelShare $share): int => $share->count, $report->labels));
        // Every run recorded exactly one of the two labels, so the two counts
        // add up to the checks and each share is that count out of a hundred.
        Assert::same($total, 100);
        Assert::same(
            array_map(static fn(LabelShare $share): float => round($share->percent, 6), $report->labels),
            array_map(static fn(LabelShare $share): float => (float) $share->count, $report->labels),
        );
        Assert::same(
            array_map(static fn(LabelShare $share): string => $share->label, $report->labels),
            ['even', 'odd'],
        );
    }

    /**
     * The denominator question, asked so it cannot be answered by accident: the
     * same body, once with half its inputs discarded. A share computed over
     * attempts would halve; over checks it does not move.
     */
    public function discardsDoNotDiluteTheLabelShares(): void
    {
        $withoutDiscards = $this->reportOf(
            static function (int $value): void {
                Classify::label('kept');
            },
            runs: 40,
        );

        $withDiscards = $this->reportOf(
            static function (int $value): void {
                Assume::that($value % 2 === 0);
                Classify::label('kept');
            },
            runs: 40,
        );

        Assert::same($withoutDiscards?->label('kept')?->percent, 100.0);
        Assert::same($withDiscards?->label('kept')?->percent, 100.0);
        // ...and the discards are still reported, on their own denominator.
        Assert::true(($withDiscards?->discardPercent() ?? 0.0) > 0.0);
        Assert::true(($withDiscards?->attempts ?? 0) > ($withDiscards?->checks ?? 0));
    }

    public function anUnmetRequirementIsReportedBesideTheShareItWasComparedTo(): void
    {
        $listener = new CollectingListener();
        $result = $this->run(
            static function (int $value): void {
                Classify::cover($value > 9_000, 'high', 90.0);
            },
            runs: 50,
            listener: $listener,
        );

        Assert::instanceOf($result, CoverageFailed::class);

        $report = $this->distributionOf($listener);
        Assert::instanceOf($report, DistributionReport::class);
        Assert::true($report->coverageAssessed);
        Assert::same($report->label('high')?->required, 90.0);
        Assert::same(
            array_map(static fn(LabelShare $share): string => $share->label, $report->unmetRequirements()),
            ['high'],
        );
    }

    public function anOutcomeThatNeverReachedTheAssessmentSaysSo(): void
    {
        $listener = new CollectingListener();
        $result = $this->run(
            static function (int $value): void {
                Classify::cover(condition: true, label: 'covered', minPercent: 1.0);
                Assume::that(condition: false);
            },
            runs: 1,
            listener: $listener,
        );

        Assert::instanceOf($result, GaveUp::class);

        $report = $this->distributionOf($listener);
        Assert::instanceOf($report, DistributionReport::class);
        // The body registered the requirement, so it is reported — but nothing
        // judged it: every run was discarded before the loop could complete.
        Assert::same($report->label('covered')?->required, 1.0);
        Assert::same($report->label('covered')?->count, 0);
        Assert::false($report->coverageAssessed);
        Assert::same($report->discardPercent(), 100.0);
    }

    public function anExhaustedBudgetCarriesTheSharesItGotTo(): void
    {
        // Phase start 0ms; budget checks at 6ms (elapsed 2), 12ms (elapsed 8,
        // not strictly greater), 18ms (elapsed 14 > 8ms budget).
        $listener = new CollectingListener();
        $result = (new PropertyRunner(new FakeClock(stepNs: 2_000_000)))->run(
            $this->definition(runs: 5, budgetMs: 8),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::cover(condition: true, label: 'covered', minPercent: 1.0);
            }),
            [$listener],
        );

        Assert::instanceOf($result, TimeBudgetExceeded::class);

        $report = $this->distributionOf($listener);
        Assert::instanceOf($report, DistributionReport::class);
        Assert::same($report->checks, 2);
        Assert::same($report->label('covered')?->count, 2);
        Assert::false($report->coverageAssessed);
    }

    public function aFalsifiedRunReportsNoDistribution(): void
    {
        $listener = new CollectingListener();
        $result = $this->run(
            static function (int $value): void {
                Classify::label('seen');

                if ($value >= 0) {
                    throw new \RuntimeException('always fails');
                }
            },
            runs: 10,
            listener: $listener,
        );

        Assert::instanceOf($result, Falsified::class);
        // Deliberate: a falsification stops at the counterexample, and the
        // result carries no statistics for the report to project.
        Assert::null($this->distributionOf($listener));
    }

    public function aRunWithoutTheRandomPhaseReportsHonestZeroes(): void
    {
        $listener = new CollectingListener();
        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'distribution::property',
                name: 'property',
                generators: ['value' => Gen::intBetween(0, 10_000)],
                parameterNames: ['value'],
                config: new PropertyConfig(runs: 50, seed: 42, phases: [Phase::Examples, Phase::Corpus]),
            ),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::label('never recorded');
            }),
            [$listener],
        );

        Assert::instanceOf($result, Passed::class);

        $report = $this->distributionOf($listener);
        Assert::instanceOf($report, DistributionReport::class);
        Assert::same($report->labels, []);
        Assert::same($report->attempts, 0);
        Assert::same($report->discardPercent(), 0.0);
        Assert::false($report->coverageAssessed);
    }

    /**
     * @param \Closure(int): void $body
     */
    private function reportOf(\Closure $body, int $runs): ?DistributionReport
    {
        $listener = new CollectingListener();
        $this->run($body, $runs, $listener);

        return $this->distributionOf($listener);
    }

    private function distributionOf(CollectingListener $listener): ?DistributionReport
    {
        $finished = $listener->ofType(PropertyFinished::class);
        Assert::same(count($finished), 1);

        return $finished[0]->distribution;
    }

    /**
     * @param \Closure(int): void $body
     */
    private function run(
        \Closure $body,
        int $runs,
        CollectingListener $listener,
    ): PropertyResult {
        return (new PropertyRunner())->run(
            $this->definition(runs: $runs),
            new CallableTrialExecutor($body),
            [$listener],
        );
    }

    private function definition(int $runs, ?int $budgetMs = null): PropertyDefinition
    {
        return new PropertyDefinition(
            id: 'distribution::property',
            name: 'property',
            generators: ['value' => Gen::intBetween(0, 10_000)],
            parameterNames: ['value'],
            config: new PropertyConfig(runs: $runs, seed: 42, budgetMs: $budgetMs),
        );
    }
}
