<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Event\ExampleStarted;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\Event\ShrinkTried;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Rasuvaeff\PropertyTesting\Runner\CoverageFailed;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Rasuvaeff\PropertyTesting\Tests\Support\FakeClock;
use Rasuvaeff\PropertyTesting\Tests\Support\RecordingCorpus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Pins the phase set and the shrink modes: which stages a subset performs,
 * what the statistics say when the random phase never ran, and the two ways
 * the descent can be cut short. Every input is seed-pinned and the budget runs
 * on a {@see FakeClock}, so the numbers below are exact rather than
 * approximate.
 */
#[Test]
#[Covers(PropertyRunner::class)]
final class PropertyRunnerPhasesTest
{
    public function shrinkModeOffReportsTheCounterexampleAsGenerated(): void
    {
        $listener = new CollectingListener();

        $result = $this->runFalsifiable(new PropertyConfig(runs: 200, seed: 42, shrink: ShrinkMode::Off), $listener);

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->originalArguments, ['value' => 3989]);
        Assert::same($example->shrunkArguments, ['value' => 3989]);
        Assert::same($example->shrinkSteps, 0);
        Assert::same($example->shrinkTrials, 0);
        // Nothing was minimised, so the reported failure is the original one.
        Assert::same($example->failure?->getMessage(), '3989 is not below 100');

        Assert::same(count($listener->ofType(ShrinkTried::class)), 0);
        Assert::same(count($listener->ofType(ShrinkAccepted::class)), 0);
    }

    public function aPhaseSetWithoutShrinkIsExactlyShrinkModeOff(): void
    {
        // The coupling this pair of knobs must never lose: two spellings, one
        // behaviour, down to the trial counter.
        $viaMode = $this->runFalsifiable(new PropertyConfig(runs: 200, seed: 42, shrink: ShrinkMode::Off));
        $viaPhases = $this->runFalsifiable(new PropertyConfig(
            runs: 200,
            seed: 42,
            phases: [Phase::Examples, Phase::Corpus, Phase::Random],
        ));

        Assert::instanceOf($viaMode, Falsified::class);
        Assert::instanceOf($viaPhases, Falsified::class);
        Assert::same($this->fingerprint($viaPhases->counterExample()), $this->fingerprint($viaMode->counterExample()));
    }

    public function aShrinkBudgetStopsTheDescentAtTheBestCandidateSoFar(): void
    {
        // The full descent needs 9 accepted steps and 39 trials to reach 100
        // (PropertyRunnerShrinkTest). Every budget check advances the fake
        // clock by 1ms, so a 5ms budget cuts the descent off partway.
        $result = $this->runFalsifiable(
            new PropertyConfig(runs: 200, seed: 42, shrinkBudgetMs: 5),
            clock: new FakeClock(stepNs: 1_000_000),
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();

        // Exact, not "somewhere in between": the budget check is an inclusive
        // comparison against the deadline, and only a pinned step count says
        // which side of it the descent stopped on.
        Assert::same($example->shrunkArguments, ['value' => 250]);
        Assert::same($example->shrinkSteps, 4);
        Assert::same($example->shrinkTrials, 8);
        // Cut short: neither the untouched original nor the fully minimised 100.
        Assert::same($example->failure?->getMessage(), '250 is not below 100');
    }

    public function theShrinkBudgetConvertsMillisecondsToNanosecondsExactly(): void
    {
        // A clock ticking one nanosecond short of a millisecond: against a 1 ms
        // budget the first reading (999_999 ns) is still inside the deadline,
        // so exactly one candidate search happens before the next reading ends
        // the descent. One nanosecond off in the conversion and the descent
        // would never start at all.
        $result = $this->runFalsifiable(
            new PropertyConfig(runs: 200, seed: 42, shrinkBudgetMs: 1),
            clock: new FakeClock(stepNs: 999_999),
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();

        Assert::same($example->shrunkArguments, ['value' => 1995]);
        Assert::same($example->shrinkSteps, 1);
        Assert::same($example->shrinkTrials, 2);
    }

    public function aShrinkBudgetAlsoStopsTheTapeWalk(): void
    {
        // The descent walks in-body draws as extra positions after the
        // parameters, with its own budget check; without a parameter to shrink
        // this run exercises that walk alone. Unbounded it needs 7 steps and 22
        // trials to reach 10 (PropertyRunnerShrinkTest).
        $result = (new PropertyRunner(new FakeClock(stepNs: 1_000_000)))->run(
            new PropertyDefinition(
                id: 'phases::draws',
                name: 'draws',
                generators: [],
                parameterNames: [],
                config: new PropertyConfig(runs: 50, seed: 42, shrinkBudgetMs: 3),
            ),
            new CallableTrialExecutor(static function (): void {
                $draw = Gen::draw(Gen::intBetween(0, 1000));

                if ($draw >= 10) {
                    throw new \RuntimeException(sprintf('draw %d too big', $draw));
                }
            }),
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();

        Assert::same($example->originalArguments, ['draw#1' => 359]);
        Assert::same($example->shrunkArguments, ['draw#1' => 90]);
        Assert::same($example->shrinkSteps, 2);
        Assert::same($example->shrinkTrials, 4);
    }

    public function aBudgetThatOutlastsTheDescentChangesNothing(): void
    {
        $bounded = $this->runFalsifiable(
            new PropertyConfig(runs: 200, seed: 42, shrinkBudgetMs: 10_000),
            clock: new FakeClock(stepNs: 1_000_000),
        );
        $full = $this->runFalsifiable(new PropertyConfig(runs: 200, seed: 42));

        Assert::instanceOf($bounded, Falsified::class);
        Assert::instanceOf($full, Falsified::class);
        Assert::same($this->fingerprint($bounded->counterExample()), $this->fingerprint($full->counterExample()));
    }

    public function withoutTheRandomPhaseTheStatisticsAreHonestZeros(): void
    {
        $listener = new CollectingListener();

        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'phases::property',
                name: 'property',
                generators: ['value' => Gen::intBetween(0, 10_000)],
                parameterNames: ['value'],
                config: new PropertyConfig(runs: 100, seed: 42, phases: [Phase::Examples]),
                examples: [[1]],
            ),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value >= 100) {
                    throw new \RuntimeException(sprintf('%d is not below 100', $value));
                }
            }),
            [$listener],
        );

        Assert::instanceOf($result, Passed::class);
        // Not "attempts: 100": nothing generated, and the result says so.
        Assert::same($result->statistics->attempts, 0);
        Assert::same($result->statistics->checks, 0);
        Assert::same($result->statistics->discards, 0);
        Assert::same($result->statistics->classifications, []);

        Assert::same(count($listener->ofType(ExampleStarted::class)), 1);
        Assert::same(count($listener->ofType(RunStarted::class)), 0);
    }

    public function withoutTheExamplesPhaseAFailingExampleIsNotRun(): void
    {
        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'phases::property',
                name: 'property',
                generators: ['value' => Gen::intBetween(0, 10)],
                parameterNames: ['value'],
                config: new PropertyConfig(runs: 5, seed: 42, phases: [Phase::Random, Phase::Shrink]),
                examples: [[-1]],
            ),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value < 0) {
                    throw new \RuntimeException('negative');
                }
            }),
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->checks, 5);
    }

    public function withoutTheCorpusPhaseNothingIsReplayedButAFreshFailureIsStillStored(): void
    {
        $corpus = new RecordingCorpus([CorpusEntry::values(['value' => -1], seed: 7)]);

        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'phases::property',
                name: 'property',
                generators: ['value' => Gen::intBetween(0, 10_000)],
                parameterNames: ['value'],
                config: new PropertyConfig(runs: 200, seed: 42, phases: [Phase::Random, Phase::Shrink]),
            ),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value >= 100) {
                    throw new \RuntimeException(sprintf('%d is not below 100', $value));
                }
            }),
            [],
            $corpus,
        );

        Assert::instanceOf($result, Falsified::class);
        // The recorded entry was neither replayed nor pruned...
        Assert::same($corpus->pruned, []);
        // ...while storing a new falsification is not a phase and still happens.
        Assert::same(count($corpus->remembered), 1);
    }

    public function aPhaseRestrictedRunLeavesNoArmedCoverageRequirement(): void
    {
        $runner = new PropertyRunner();

        // The example arms a coverage requirement that the skipped random phase
        // can never satisfy; the requirement must be dropped, not leak into the
        // next property in this process.
        $first = $runner->run(
            new PropertyDefinition(
                id: 'phases::first',
                name: 'first',
                generators: ['value' => Gen::intBetween(0, 10)],
                parameterNames: ['value'],
                config: new PropertyConfig(runs: 10, seed: 42, phases: [Phase::Examples]),
                examples: [[1]],
            ),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::cover($value > 100, 'unreachable', 90.0);
            }),
        );

        Assert::instanceOf($first, Passed::class);
        // Directly observable: the run drained the requirement itself. Without
        // this assertion the next run's own defensive flush would hide a leak.
        Assert::same(Classify::flushRequirements(), []);

        $second = $runner->run(
            new PropertyDefinition(
                id: 'phases::second',
                name: 'second',
                generators: ['value' => Gen::intBetween(0, 10)],
                parameterNames: ['value'],
                config: new PropertyConfig(runs: 10, seed: 42),
            ),
            new CallableTrialExecutor(static function (int $value): void {
                Assert::true($value >= 0);
            }),
        );

        Assert::instanceOf($second, Passed::class);
        Assert::false($second instanceof CoverageFailed);
    }

    /**
     * The counterexample fields two configurations must agree on, as one
     * comparable value.
     *
     * @return array{array<string, mixed>, array<string, mixed>, int, int, ?string}
     */
    private function fingerprint(CounterExample $example): array
    {
        return [
            $example->originalArguments,
            $example->shrunkArguments,
            $example->shrinkSteps,
            $example->shrinkTrials,
            $example->failure?->getMessage(),
        ];
    }

    /**
     * The falsifiable property every shrink-mode case shares: the first failing
     * input for seed 42 is 3989, and the fully minimised one is 100.
     */
    private function runFalsifiable(
        PropertyConfig $config,
        ?CollectingListener $listener = null,
        ?FakeClock $clock = null,
    ): \Rasuvaeff\PropertyTesting\Runner\PropertyResult {
        return (new PropertyRunner($clock))->run(
            new PropertyDefinition(
                id: 'phases::property',
                name: 'property',
                generators: ['value' => Gen::intBetween(0, 10_000)],
                parameterNames: ['value'],
                config: $config,
            ),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value >= 100) {
                    throw new \RuntimeException(sprintf('%d is not below 100', $value));
                }
            }),
            $listener instanceof CollectingListener ? [$listener] : [],
        );
    }
}
