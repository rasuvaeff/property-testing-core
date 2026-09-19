<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Event\PropertyFinished;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Rasuvaeff\PropertyTesting\Runner\DistributionReport;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\RegressionFailed;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Rasuvaeff\PropertyTesting\Tests\Support\RecordingCorpus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Exhaustive mode: the random phase walks the parameter product when every
 * generator has a finite domain that fits the budget, and says why when it
 * samples instead. The walk is seed-independent; in-body draws are not.
 */
#[Test]
#[Covers(PropertyRunner::class)]
final class PropertyRunnerExhaustiveTest
{
    public function walksTheWholeProductInParameterMajorOrderAndReportsTheSize(): void
    {
        $listener = new CollectingListener();
        $seen = [];

        $result = $this->run(
            ['flag' => Gen::bool(), 'n' => Gen::intBetween(0, 2)],
            static function (bool $flag, int $n) use (&$seen): void {
                $seen[] = [$flag, $n];
            },
            listener: $listener,
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($seen, [[false, 0], [false, 1], [false, 2], [true, 0], [true, 1], [true, 2]]);
        Assert::same($result->statistics->attempts, 6);
        Assert::same($result->statistics->checks, 6);
        Assert::same($result->statistics->domainSize, 6);
        Assert::null($result->statistics->exhaustiveDeclined);
        Assert::same(count($listener->ofType(RunStarted::class)), 6);

        $report = $this->reportOf($listener);
        Assert::same($report->domainSize, 6);
        Assert::same($report->toArray()['exhaustive'], ['domainSize' => 6]);
    }

    public function theWalkIsTheSameForEverySeed(): void
    {
        $walk = static function (int $seed): array {
            $seen = [];
            (new PropertyRunner())->run(
                new PropertyDefinition(
                    id: 'exhaustive::property',
                    name: 'property',
                    generators: ['a' => Gen::elements(['x', 'y', 'z']), 'b' => Gen::bool()],
                    parameterNames: ['a', 'b'],
                    config: new PropertyConfig(runs: 3, seed: $seed, exhaustive: true),
                ),
                new CallableTrialExecutor(static function (string $a, bool $b) use (&$seen): void {
                    $seen[] = $a . ($b ? '+' : '-');
                }),
            );

            return $seen;
        };

        Assert::same($walk(1), $walk(999));
        Assert::same(count($walk(1)), 6);
    }

    public function runsIsIgnoredInFavourOfTheDomain(): void
    {
        $result = $this->run(['n' => Gen::intBetween(0, 9)], static function (int $n): void {}, runs: 3);

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->checks, 10);
    }

    public function declinesAParameterWithoutAFiniteDomainAndSamples(): void
    {
        $listener = new CollectingListener();
        $result = $this->run(
            ['flag' => Gen::bool(), 's' => Gen::string()],
            static function (bool $flag, string $s): void {},
            runs: 25,
            listener: $listener,
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->checks, 25);
        Assert::null($result->statistics->domainSize);
        Assert::same($result->statistics->exhaustiveDeclined, 'parameter "s" has no finite domain to enumerate');
        Assert::same($this->reportOf($listener)->toArray()['exhaustive'], ['declined' => 'parameter "s" has no finite domain to enumerate']);
    }

    public function declinesADomainAboveTheBudgetAndSamples(): void
    {
        $result = $this->run(
            ['a' => Gen::intBetween(0, 99), 'b' => Gen::intBetween(0, 99)],
            static function (int $a, int $b): void {},
            runs: 25,
            budget: 9_999,
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->checks, 25);
        Assert::same($result->statistics->exhaustiveDeclined, 'the domain has 10000 inputs, above the exhaustive budget of 9999');

        $saturated = $this->run(['n' => Gen::int()], static function (int $n): void {}, runs: 5);
        Assert::instanceOf($saturated, Passed::class);
        Assert::same($saturated->statistics->exhaustiveDeclined, 'the domain has more than ' . PHP_INT_MAX . ' inputs, above the exhaustive budget of 10000');
    }

    public function aDomainExactlyAtTheBudgetIsWalked(): void
    {
        $result = $this->run(['n' => Gen::intBetween(1, 10)], static function (int $n): void {}, budget: 10);

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->domainSize, 10);
    }

    public function withoutTheFlagNothingIsEnumeratedOrReported(): void
    {
        $listener = new CollectingListener();
        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'exhaustive::property',
                name: 'property',
                generators: ['n' => Gen::intBetween(0, 1)],
                parameterNames: ['n'],
                config: new PropertyConfig(runs: 7, seed: 1),
            ),
            new CallableTrialExecutor(static function (int $n): void {}),
            [$listener],
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->checks, 7);
        Assert::null($result->statistics->domainSize);
        Assert::null($result->statistics->exhaustiveDeclined);
        Assert::false(array_key_exists('exhaustive', $this->reportOf($listener)->toArray()));
    }

    public function aFalsifiedInputShrinksThroughItsTree(): void
    {
        $result = $this->run(
            ['n' => Gen::intBetween(0, 50)],
            static function (int $n): void {
                if ($n >= 30) {
                    throw new \RuntimeException('too big');
                }
            },
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->originalArguments, ['n' => 30]);
        Assert::same($example->shrunkArguments, ['n' => 30]);
        Assert::same($example->runsBeforeFailure, 30);
    }

    public function aDiscardedInputIsSkippedNotResampled(): void
    {
        $result = $this->run(
            ['n' => Gen::intBetween(0, 9)],
            static function (int $n): void {
                Assume::that($n % 2 === 0);
            },
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->attempts, 10);
        Assert::same($result->statistics->discards, 5);
        Assert::same($result->statistics->checks, 5);
    }

    public function theDiscardBudgetStillApplies(): void
    {
        $result = $this->run(
            ['n' => Gen::intBetween(0, 99)],
            static function (int $n): void {
                Assume::that(false);
            },
            maxDiscards: 3,
        );

        Assert::instanceOf($result, GaveUp::class);
    }

    public function aFilteredDomainWalksFewerInputsThanItsBound(): void
    {
        $result = $this->run(
            ['n' => Gen::filter(Gen::intBetween(0, 9), static fn(int $n): bool => $n > 6)],
            static function (int $n): void {},
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->domainSize, 10);
        Assert::same($result->statistics->checks, 3);
    }

    public function inBodyDrawsStayRandomAndSeeded(): void
    {
        $draws = static function (int $seed): array {
            $seen = [];
            (new PropertyRunner())->run(
                new PropertyDefinition(
                    id: 'exhaustive::property',
                    name: 'property',
                    generators: ['flag' => Gen::bool()],
                    parameterNames: ['flag'],
                    config: new PropertyConfig(runs: 1, seed: $seed, exhaustive: true),
                ),
                new CallableTrialExecutor(static function (bool $flag) use (&$seen): void {
                    $seen[] = Gen::draw(Gen::intBetween(0, 1_000_000));
                }),
            );

            return $seen;
        };

        Assert::same($draws(5), $draws(5));
        Assert::true($draws(5) !== $draws(6));
    }

    public function aSeedEntryRecordedUnderEnumerationReplaysTheSameWalk(): void
    {
        $body = static function (bool $flag): void {
            if ($flag && Gen::draw(Gen::intBetween(0, 1)) >= 0) {
                throw new \RuntimeException('true fails');
            }
        };

        $corpus = new RecordingCorpus();
        $first = $this->run(['flag' => Gen::bool()], $body, corpus: $corpus);
        Assert::instanceOf($first, Falsified::class);
        Assert::same(count($corpus->remembered), 1);
        $example = $corpus->remembered[0][1];

        // Replayed as a seed entry (the counterexample carries a draw): the
        // same walk reaches the same failing input.
        $replaying = new RecordingCorpus([CorpusEntry::seed($example->seed, $example->runsBeforeFailure)]);
        $second = $this->run(['flag' => Gen::bool()], $body, corpus: $replaying);

        Assert::instanceOf($second, Falsified::class);
        Assert::same($second->counterExample()->shrunkArguments['flag'], true);
        Assert::same($replaying->pruned, []);
    }

    public function aValuesEntryStillReplaysFirst(): void
    {
        $corpus = new RecordingCorpus([CorpusEntry::values(['n' => 3], seed: 1)]);
        $result = $this->run(
            ['n' => Gen::intBetween(0, 5)],
            static function (int $n): void {
                if ($n === 3) {
                    throw new \RuntimeException('three');
                }
            },
            corpus: $corpus,
        );

        Assert::instanceOf($result, RegressionFailed::class);
    }

    /**
     * @param array<string, ArbitraryInterface> $generators
     */
    private function run(
        array $generators,
        \Closure $body,
        int $runs = 10,
        int $budget = 10_000,
        ?int $maxDiscards = null,
        ?CollectingListener $listener = null,
        ?RecordingCorpus $corpus = null,
    ): PropertyResult {
        return (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'exhaustive::property',
                name: 'property',
                generators: $generators,
                parameterNames: array_keys($generators),
                config: new PropertyConfig(runs: $runs, seed: 42, maxDiscards: $maxDiscards, exhaustive: true, exhaustiveBudget: $budget),
            ),
            new CallableTrialExecutor($body),
            $listener === null ? [] : [$listener],
            $corpus,
        );
    }

    private function reportOf(CollectingListener $listener): DistributionReport
    {
        $finished = $listener->ofType(PropertyFinished::class);
        Assert::same(count($finished), 1);
        $report = $finished[0]->distribution;
        Assert::instanceOf($report, DistributionReport::class);

        return $report;
    }
}
