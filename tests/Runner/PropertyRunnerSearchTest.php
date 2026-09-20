<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Event\CorpusFailed;
use Rasuvaeff\PropertyTesting\Event\PropertyFinished;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Event\TargetImproved;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\SearchReport;
use Rasuvaeff\PropertyTesting\Runner\TargetDirection;
use Rasuvaeff\PropertyTesting\Target;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Rasuvaeff\PropertyTesting\Tests\Support\RecordingSearchCorpus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Targeted search: a body that reports a score gets a search phase after
 * the random one, climbing the score by mutating the best inputs one
 * parameter at a time. The first test is the go/no-go measurement the
 * feature was gated on.
 */
#[Test]
#[Covers(PropertyRunner::class)]
final class PropertyRunnerSearchTest
{
    /**
     * A bug that lives in a corner: three parameters in [0, 1000] whose sum
     * must exceed 2900 — about 0.17% of the space. Plain sampling at 300
     * runs finds it with probability ~40% per seed; 200 random runs plus 100
     * search runs climbing the sum find it nearly every time. Measured over
     * fixed seeds so the numbers are the same on every machine.
     */
    public function climbingTheSumFindsACornerBugSamplingMisses(): void
    {
        $body = static function (int $a, int $b, int $c): void {
            Target::maximize('sum', $a + $b + $c);

            if ($a + $b + $c > 2_900) {
                throw new \RuntimeException('corner reached');
            }
        };

        $sampled = 0;
        $searched = 0;
        $seeds = range(1, 25);

        foreach ($seeds as $seed) {
            if ($this->run($body, runs: 300, searchRuns: 0, seed: $seed) instanceof Falsified) {
                ++$sampled;
            }

            if ($this->run($body, runs: 200, searchRuns: 100, seed: $seed) instanceof Falsified) {
                ++$searched;
            }
        }

        // The measurement: same body, same total budget of 300 executions.
        Assert::true($sampled <= 15, sprintf('sampling found the corner in %d of 25 seeds', $sampled));
        Assert::true($searched >= 22, sprintf('search found the corner in %d of 25 seeds', $searched));
        Assert::true($searched > $sampled + 5, sprintf('search %d vs sampling %d', $searched, $sampled));
    }

    public function withoutSearchRunsNothingChangesForATargetingBody(): void
    {
        $listener = new CollectingListener();
        $result = $this->run(static function (int $a, int $b, int $c): void {
            Target::maximize('sum', $a + $b + $c);
        }, runs: 20, searchRuns: 0, listener: $listener);

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->checks, 20);
        Assert::null($result->statistics->search);
        Assert::null($this->finished($listener)->search);
        // Improvements are announced in the random phase too: cheap, and
        // the first score of a label is always one.
        Assert::true(count($listener->ofType(TargetImproved::class)) >= 1);
    }

    public function aBodyThatTargetsNothingGetsNoSearchPhase(): void
    {
        $listener = new CollectingListener();
        $result = $this->run(static function (int $a, int $b, int $c): void {}, runs: 20, searchRuns: 50, listener: $listener);

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->checks, 20);
        Assert::null($result->statistics->search);
        Assert::same(count($listener->ofType(TargetImproved::class)), 0);
    }

    public function theSearchPhaseReportsEvaluationsAndEveryLabel(): void
    {
        $listener = new CollectingListener();
        $result = $this->run(static function (int $a, int $b, int $c): void {
            Target::maximize('sum', $a + $b + $c);
            Target::minimize('a', $a);
        }, runs: 30, searchRuns: 40, listener: $listener);

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->checks, 70);
        Assert::same(count($listener->ofType(RunStarted::class)), 70);

        $report = $result->statistics->search;
        Assert::instanceOf($report, SearchReport::class);
        Assert::same($report->evaluations, 40);
        Assert::same(array_keys($report->targets), ['sum', 'a']);
        Assert::same($report->targets['sum']->direction, TargetDirection::Maximize);
        Assert::same($report->targets['a']->direction, TargetDirection::Minimize);
        Assert::true($report->targets['sum']->best >= 0.0 && $report->targets['sum']->best <= 3000.0);
        Assert::true($report->targets['a']->best >= 0.0);
        Assert::same($report->targets['sum']->recalled, 0);
        Assert::same($this->finished($listener)->search, $report);
        Assert::same($report->toArray()['evaluations'], 40);
        Assert::same(array_keys($report->toArray()['targets']['sum']), ['direction', 'best', 'improvements', 'recalled']);

        // Every improvement was announced, with the previous best beside it,
        // and the best never went backwards.
        $improvements = array_values(array_filter($listener->ofType(TargetImproved::class), static fn(TargetImproved $e): bool => $e->label === 'sum'));
        Assert::same(count($improvements), $report->targets['sum']->improvements);
        Assert::null($improvements[0]->previous);
        Assert::same($improvements[count($improvements) - 1]->score, $report->targets['sum']->best);
        for ($i = 1; $i < count($improvements); ++$i) {
            Assert::same($improvements[$i]->previous, $improvements[$i - 1]->score);
            Assert::true($improvements[$i]->score > $improvements[$i - 1]->score);
        }
        Assert::same(array_keys($improvements[0]->arguments), ['a', 'b', 'c']);
    }

    public function aFailingSearchRunFalsifiesLikeAnyOther(): void
    {
        $result = $this->run(static function (int $a, int $b, int $c): void {
            Target::maximize('sum', $a + $b + $c);

            if ($a + $b + $c > 2_900) {
                throw new \RuntimeException('corner reached');
            }
        }, runs: 200, searchRuns: 100, seed: 3);

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::true($example->runsBeforeFailure >= 200);
        Assert::true($example->shrunkArguments['a'] + $example->shrunkArguments['b'] + $example->shrunkArguments['c'] > 2_900);
    }

    public function discardsInTheSearchPhaseSpendTheSameBudget(): void
    {
        $result = $this->run(static function (int $a, int $b, int $c): void {
            Target::maximize('sum', $a + $b + $c);
            Assume::that($a + $b + $c < 100 || $a < 5);
        }, runs: 5, searchRuns: 100, maxDiscards: 20);

        Assert::instanceOf($result, GaveUp::class);
    }

    public function theCorpusSeedsThePoolAndReceivesItBack(): void
    {
        $corpus = new RecordingSearchCorpus([
            'sum' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 2_950.0, 'arguments' => ['a' => 1_000, 'b' => 1_000, 'c' => 950]]]],
            'other' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 1.0, 'arguments' => ['a' => 1, 'b' => 1, 'c' => 1]]]],
            'a' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 999.0, 'arguments' => ['a' => 999, 'b' => 0, 'c' => 0]]]],
        ]);
        $listener = new CollectingListener();

        $result = $this->run(static function (int $a, int $b, int $c): void {
            Target::maximize('sum', $a + $b + $c);
            Target::minimize('a', $a);
        }, runs: 10, searchRuns: 10, corpus: $corpus, listener: $listener);

        Assert::instanceOf($result, Passed::class);
        $report = $result->statistics->search;
        Assert::instanceOf($report, SearchReport::class);
        // The recalled best is the floor the search starts from; a label the
        // body pushes the other way (`a`) and one it never reports are ignored.
        Assert::true($report->targets['sum']->best >= 2_950.0);
        Assert::same($report->targets['sum']->recalled, 1);
        Assert::same($report->targets['a']->recalled, 0);
        Assert::same($corpus->recalls, 1);

        Assert::same(count($corpus->rememberedTargets), 1);
        $stored = $corpus->rememberedTargets[0];
        Assert::same(array_keys($stored), ['sum', 'a']);
        Assert::same($stored['sum']['direction'], TargetDirection::Maximize);
        Assert::same($stored['sum']['entries'][0]['score'], $report->targets['sum']->best);
        Assert::same(array_keys($stored['sum']['entries'][0]['arguments']), ['a', 'b', 'c']);
        Assert::true(count($stored['sum']['entries']) <= 8);
    }

    public function aCorpusThatFailsToRecallIsReportedAndDropped(): void
    {
        $corpus = new RecordingSearchCorpus(recallFailure: new \RuntimeException('redis down'));
        $listener = new CollectingListener();

        $result = $this->run(static function (int $a, int $b, int $c): void {
            Target::maximize('sum', $a + $b + $c);
        }, runs: 5, searchRuns: 5, corpus: $corpus, listener: $listener);

        Assert::instanceOf($result, Passed::class);
        $failed = $listener->ofType(CorpusFailed::class);
        Assert::same(count($failed), 1);
        Assert::same($failed[0]->operation, 'recallTargets');
        Assert::same($corpus->rememberedTargets, []);
    }

    public function targetDirectionsDoNotLeakIntoTheNextProperty(): void
    {
        $this->run(static function (int $a, int $b, int $c): void {
            Target::maximize('sum', $a + $b + $c);
        }, runs: 3, searchRuns: 0);

        Assert::same(Target::directions(), []);

        // The next property may push the same label the other way.
        $result = $this->run(static function (int $a, int $b, int $c): void {
            Target::minimize('sum', $a + $b + $c);
        }, runs: 3, searchRuns: 0);

        Assert::instanceOf($result, Passed::class);
    }

    private function run(
        \Closure $body,
        int $runs,
        int $searchRuns,
        int $seed = 42,
        ?int $maxDiscards = null,
        ?RecordingSearchCorpus $corpus = null,
        ?CollectingListener $listener = null,
    ): PropertyResult {
        return (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'search::property',
                name: 'property',
                generators: ['a' => Gen::intBetween(0, 1000), 'b' => Gen::intBetween(0, 1000), 'c' => Gen::intBetween(0, 1000)],
                parameterNames: ['a', 'b', 'c'],
                config: new PropertyConfig(runs: $runs, seed: $seed, maxDiscards: $maxDiscards, searchRuns: $searchRuns),
            ),
            new CallableTrialExecutor($body),
            $listener === null ? [] : [$listener],
            $corpus,
        );
    }

    private function finished(CollectingListener $listener): PropertyFinished
    {
        $finished = $listener->ofType(PropertyFinished::class);
        Assert::same(count($finished), 1);

        return $finished[0];
    }
}
