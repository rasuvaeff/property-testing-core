<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Event\CorpusPruned;
use Rasuvaeff\PropertyTesting\Event\CorpusReplayed;
use Rasuvaeff\PropertyTesting\Event\CorpusStored;
use Rasuvaeff\PropertyTesting\Event\ExampleFinished;
use Rasuvaeff\PropertyTesting\Event\ExampleStarted;
use Rasuvaeff\PropertyTesting\Event\PropertyFinished;
use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunDiscarded;
use Rasuvaeff\PropertyTesting\Event\RunPassed;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\DrawContext;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\RegressionViolationException;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Rasuvaeff\PropertyTesting\Runner\CoverageFailed;
use Rasuvaeff\PropertyTesting\Runner\DeadlineExceeded;
use Rasuvaeff\PropertyTesting\Runner\ExampleFailed;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\GenerationFailed;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\RegressionFailed;
use Rasuvaeff\PropertyTesting\Runner\TimeBudgetExceeded;
use Rasuvaeff\PropertyTesting\Runner\TrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\TrialOutcome;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Rasuvaeff\PropertyTesting\Tests\Support\FakeClock;
use Rasuvaeff\PropertyTesting\Tests\Support\RecordingCorpus;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Pins the run() lifecycle in detail: listener normalization, the exact event
 * sequences and payloads, the Classify/DrawContext hygiene on every exit path,
 * corpus replay/remember, and the FakeClock-measured deadline and budget
 * boundaries. Complements {@see PropertyRunnerTest}, which pins the coarse
 * result surface.
 */
#[Test]
#[Covers(PropertyRunner::class)]
final class PropertyRunnerLifecycleTest
{
    public function listenersMayBeATraversable(): void
    {
        $listener = new CollectingListener();
        $listeners = (static function () use ($listener): \Generator {
            yield $listener;
        })();

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1),
            new CallableTrialExecutor(static function (int $value): void {}),
            $listeners,
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($listener->shapes(), ['PropertyStarted', 'RunStarted', 'RunPassed', 'PropertyFinished']);
    }

    public function passingRunEmitsTheFullLifecycle(): void
    {
        $listener = new CollectingListener();

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 2),
            new CallableTrialExecutor(static function (int $value): void {}),
            [$listener],
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($listener->shapes(), [
            'PropertyStarted',
            'RunStarted',
            'RunPassed',
            'RunStarted',
            'RunPassed',
            'PropertyFinished',
        ]);

        $started = $listener->ofType(PropertyStarted::class)[0];
        Assert::same($started->propertyId, 'lifecycle::property');
        Assert::same($started->seed, 42);
        Assert::same($started->runs, 2);

        Assert::same(
            array_map(static fn(RunStarted $event): int => $event->attempt, $listener->ofType(RunStarted::class)),
            [1, 2],
        );
        Assert::null($listener->ofType(PropertyFinished::class)[0]->failure);
    }

    public function discardsEmitRunDiscardedAndGiveUpKeepsTheDefaultCap(): void
    {
        $listener = new CollectingListener();

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1),
            new CallableTrialExecutor(static fn() => Assume::that(condition: false)),
            [$listener],
        );

        Assert::instanceOf($result, GaveUp::class);
        // The default cap is runs * 10: with runs = 1 the eleventh discard is
        // the first past the cap.
        Assert::same($result->exception->maxDiscards, 10);
        Assert::same($result->exception->discardedRuns, 11);
        Assert::same($result->exception->attempts, 11);
        Assert::same($result->statistics->discards, 11);
        Assert::same(count($listener->ofType(RunDiscarded::class)), 11);
    }

    public function staleRequirementsAreFlushedWhenARunStarts(): void
    {
        // A previously aborted property left an armed coverage requirement.
        Classify::cover(condition: true, label: 'stale', minPercent: 100.0);

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 3),
            new CallableTrialExecutor(static function (int $value): void {}),
        );

        Assert::instanceOf($result, Passed::class);
    }

    #[ExpectException(\RuntimeException::class)]
    public function staleDrawContextIsDisarmedWhenARunStarts(): void
    {
        // A previously aborted property left the draw context armed.
        DrawContext::arm(new Random(7));

        $definition = new PropertyDefinition(
            id: 'lifecycle::neverGenerates',
            name: 'property',
            generators: ['value' => Gen::filter(Gen::int(), static fn(): bool => false)],
            parameterNames: ['value'],
            config: new PropertyConfig(runs: 1, seed: 42),
        );

        $result = (new PropertyRunner())->run($definition, new CallableTrialExecutor(static function (): void {}));

        Assert::instanceOf($result, GenerationFailed::class);
        // The generation-failed path never arms the context itself, so a draw
        // outside a run must be rejected again.
        Gen::draw(Gen::int());
    }

    public function staleLabelsAreClearedBeforeTheFirstRun(): void
    {
        // A label recorded outside any run must not leak into the statistics.
        Classify::label('stale');

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1),
            new CallableTrialExecutor(static function (int $value): void {}),
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->classifications, []);
    }

    public function generationFailureDrainsClassifyState(): void
    {
        $definition = new PropertyDefinition(
            id: 'lifecycle::classifyingGenerator',
            name: 'property',
            generators: [
                'value' => Gen::filter(Gen::int(), static function (): bool {
                    Classify::cover(condition: true, label: 'from-generator', minPercent: 50.0);

                    return false;
                }),
            ],
            parameterNames: ['value'],
            config: new PropertyConfig(runs: 1, seed: 42),
        );

        $result = (new PropertyRunner())->run($definition, new CallableTrialExecutor(static function (): void {}));

        Assert::instanceOf($result, GenerationFailed::class);
        Assert::same(Classify::flushRun(), []);
        Assert::same(Classify::flushRequirements(), []);
    }

    public function exhaustedDiscardsDrainTheCoverageRequirements(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1, maxDiscards: 2),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::cover(condition: true, label: 'covered', minPercent: 1.0);
                Assume::that(condition: false);
            }),
        );

        Assert::instanceOf($result, GaveUp::class);
        Assert::same(Classify::flushRequirements(), []);
    }

    public function regressionValuesEntryReplaysBeforeTheRandomPhase(): void
    {
        $corpus = new RecordingCorpus([CorpusEntry::values(['value' => 12_345], seed: 7)]);
        $listener = new CollectingListener();
        $bodies = 0;

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 100),
            new CallableTrialExecutor(static function (int $value) use (&$bodies): void {
                ++$bodies;

                if ($value >= 100) {
                    throw new \RuntimeException('still failing');
                }
            }),
            [$listener],
            $corpus,
        );

        Assert::instanceOf($result, RegressionFailed::class);
        Assert::instanceOf($result->failure(), RegressionViolationException::class);
        Assert::same($result->exception->getArguments(), ['value' => 12_345]);
        Assert::same($result->exception->getSeed(), 7);
        Assert::same($bodies, 1);
        Assert::same($corpus->pruned, []);

        $replayed = $listener->ofType(CorpusReplayed::class);
        Assert::same(count($replayed), 1);
        Assert::true($replayed[0]->isValues);
        Assert::same($replayed[0]->arguments, ['value' => 12_345]);
        Assert::same($replayed[0]->seed, 7);
    }

    public function regressionReplayOrdersArgumentsByParameterList(): void
    {
        $corpus = new RecordingCorpus([CorpusEntry::values(['b' => 2, 'a' => 1], seed: 9)]);
        $executor = new class implements TrialExecutor {
            /** @var list<array<string, mixed>> */
            public array $captured = [];

            #[\Override]
            public function execute(array $arguments): TrialOutcome
            {
                $this->captured[] = $arguments;

                return TrialOutcome::passed();
            }
        };

        $definition = new PropertyDefinition(
            id: 'lifecycle::twoParameters',
            name: 'property',
            generators: ['a' => Gen::intBetween(0, 10), 'b' => Gen::intBetween(0, 10)],
            parameterNames: ['a', 'b'],
            config: new PropertyConfig(runs: 1, seed: 42),
        );

        $result = (new PropertyRunner())->run($definition, $executor, corpus: $corpus);

        Assert::instanceOf($result, Passed::class);
        // The replay reorders the recorded values into declaration order and
        // keeps them keyed by parameter name.
        Assert::same($executor->captured[0], ['a' => 1, 'b' => 2]);
        Assert::same(count($corpus->pruned), 1);
    }

    public function prunedRegressionEntryMayDraw(): void
    {
        $entry = CorpusEntry::values(['value' => 5], seed: 3);
        $corpus = new RecordingCorpus([$entry]);
        $listener = new CollectingListener();

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1),
            new CallableTrialExecutor(static function (int $value): void {
                Gen::draw(Gen::intBetween(1, 3));
            }),
            [$listener],
            $corpus,
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($corpus->pruned, [$entry]);

        $pruned = $listener->ofType(CorpusPruned::class);
        Assert::same(count($pruned), 1);
        Assert::true($pruned[0]->isValues);
        Assert::same($pruned[0]->seed, 3);
    }

    public function anInconclusiveSeedReplayIsReportedAndKeepsTheEntry(): void
    {
        $entry = CorpusEntry::seed(11);
        $corpus = new RecordingCorpus([$entry]);

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1),
            new CallableTrialExecutor(static fn() => Assume::that(condition: false)),
            [],
            $corpus,
        );

        // The seed replay gave up. That verdict surfaces as the result, and
        // the recorded regression is NOT pruned — it was never proven green.
        Assert::instanceOf($result, GaveUp::class);
        Assert::same($corpus->pruned, []);
    }

    public function aTimedOutRunEmitsNoRunPassed(): void
    {
        $listener = new CollectingListener();

        $result = (new PropertyRunner(new FakeClock(stepNs: 5_000_001)))->run(
            $this->definition(runs: 3, timeoutMs: 5),
            new CallableTrialExecutor(static function (int $value): void {}),
            [$listener],
        );

        // The run failed its deadline, so it must not be announced as passed.
        Assert::instanceOf($result, DeadlineExceeded::class);
        Assert::same($listener->shapes(), ['PropertyStarted', 'RunStarted', 'PropertyFinished']);
    }

    public function overlongRegressionReplayFailsTheDeadline(): void
    {
        $corpus = new RecordingCorpus([CorpusEntry::values(['value' => 2], seed: 3)]);

        $result = (new PropertyRunner(new FakeClock(stepNs: 5_000_001)))->run(
            $this->definition(runs: 1, timeoutMs: 5),
            new CallableTrialExecutor(static function (int $value): void {}),
            corpus: $corpus,
        );

        Assert::instanceOf($result, DeadlineExceeded::class);
        Assert::same($result->exception->elapsedMs, 5_000_001 / 1e6);
        Assert::same($result->exception->arguments, ['value' => 2]);
        // A timed-out replay is reported, not pruned.
        Assert::same($corpus->pruned, []);
    }

    public function replayDeadlineBoundaryEqualElapsedPasses(): void
    {
        $corpus = new RecordingCorpus([CorpusEntry::values(['value' => 2], seed: 3)]);

        $result = (new PropertyRunner(new FakeClock(stepNs: 5_000_000)))->run(
            $this->definition(runs: 1, timeoutMs: 5),
            new CallableTrialExecutor(static function (int $value): void {}),
            corpus: $corpus,
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same(count($corpus->pruned), 1);
    }

    public function regressionReplayMeasuresElapsedFromItsOwnStart(): void
    {
        // The clock keeps advancing across the example and the replay; the 2ms
        // replay stays under the 5ms deadline only when elapsed is measured
        // from the replay's own start.
        $corpus = new RecordingCorpus([CorpusEntry::values(['value' => 2], seed: 3)]);

        $result = (new PropertyRunner(new FakeClock(stepNs: 2_000_000)))->run(
            $this->definition(runs: 1, timeoutMs: 5, examples: [[1]]),
            new CallableTrialExecutor(static function (int $value): void {}),
            corpus: $corpus,
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same(count($corpus->pruned), 1);
    }

    #[ExpectException(\RuntimeException::class)]
    public function failingRegressionReplayDisarmsTheDrawContext(): void
    {
        $corpus = new RecordingCorpus([CorpusEntry::values(['value' => 12_345], seed: 7)]);

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value >= 100) {
                    throw new \RuntimeException('still failing');
                }
            }),
            corpus: $corpus,
        );

        Assert::instanceOf($result, RegressionFailed::class);
        // The early regression-failure return must leave the context disarmed.
        Gen::draw(Gen::int());
    }

    public function failingRegressionReplayDrainsItsRunLabels(): void
    {
        $corpus = new RecordingCorpus([CorpusEntry::values(['value' => 12_345], seed: 7)]);

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::label('from-replay');

                throw new \RuntimeException('still failing');
            }),
            corpus: $corpus,
        );

        Assert::instanceOf($result, RegressionFailed::class);
        Assert::same(Classify::flushRun(), []);
    }

    public function abortedRegressionReplayLeavesNoStaleLabels(): void
    {
        Classify::label('stale');

        $corpus = new RecordingCorpus([CorpusEntry::values(['value' => 1], seed: 3)]);
        $executor = new class implements TrialExecutor {
            #[\Override]
            public function execute(array $arguments): TrialOutcome
            {
                throw new \RuntimeException('infrastructure failure');
            }
        };

        $caught = false;

        try {
            (new PropertyRunner())->run($this->definition(runs: 1), $executor, corpus: $corpus);
        } catch (\RuntimeException) {
            $caught = true;
        }

        DrawContext::disarm();

        Assert::true($caught);
        // beginRun cleared the stale label before the aborted replay executed.
        Assert::same(Classify::flushRun(), []);
    }

    public function falsificationIsRememberedInTheCorpus(): void
    {
        $corpus = new RecordingCorpus();
        $listener = new CollectingListener();

        $definition = new PropertyDefinition(
            id: 'lifecycle::property',
            name: 'property',
            generators: ['value' => Gen::intBetween(0, 10_000)],
            parameterNames: ['value'],
            config: new PropertyConfig(runs: 100, seed: 42),
        );

        $result = (new PropertyRunner())->run(
            $definition,
            new CallableTrialExecutor(static function (int $value): void {
                if ($value >= 100) {
                    throw new \RuntimeException('too big');
                }
            }),
            [$listener],
            $corpus,
        );

        Assert::instanceOf($result, Falsified::class);
        Assert::same(count($corpus->remembered), 1);
        Assert::same($corpus->remembered[0][0], 'lifecycle::property');
        Assert::same($corpus->remembered[0][1], $result->counterExample());
        Assert::same($corpus->remembered[0][2], ['value']);

        $stored = $listener->ofType(CorpusStored::class);
        Assert::same(count($stored), 1);
        Assert::same($stored[0]->counterExample, $result->counterExample());
    }

    public function budgetExhaustionReportsExactElapsedAndProgress(): void
    {
        // Reads: example run start 0ms / end 2ms; phase start 4ms; budget
        // checks at 6ms (elapsed 2), 12ms (elapsed 8, not strictly greater),
        // 18ms (elapsed 14 > 8ms budget).
        $result = (new PropertyRunner(new FakeClock(stepNs: 2_000_000)))->run(
            $this->definition(runs: 5, budgetMs: 8, examples: [[3]]),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::cover(condition: true, label: 'covered', minPercent: 1.0);
            }),
        );

        Assert::instanceOf($result, TimeBudgetExceeded::class);
        Assert::same($result->exception->budgetMs, 8);
        Assert::same($result->exception->elapsedMs, 14_000_000 / 1e6);
        Assert::same($result->exception->successfulRuns, 2);
        Assert::same($result->exception->requiredRuns, 5);
        Assert::same($result->statistics->attempts, 2);
        Assert::same($result->statistics->checks, 2);
        Assert::same(Classify::flushRequirements(), []);
    }

    public function budgetBoundaryIsStrictlyGreater(): void
    {
        // Phase start 0; budget checks at 2000001ns and 8000004ns — the second
        // is the first reading strictly above the 8ms budget.
        $result = (new PropertyRunner(new FakeClock(stepNs: 2_000_001)))->run(
            $this->definition(runs: 5, budgetMs: 8),
            new CallableTrialExecutor(static function (int $value): void {}),
        );

        Assert::instanceOf($result, TimeBudgetExceeded::class);
        Assert::same($result->exception->elapsedMs, 8_000_004 / 1e6);
        Assert::same($result->exception->successfulRuns, 1);
    }

    public function overlongPassingRunFailsTheDeadlineWithDrawsInArguments(): void
    {
        $result = (new PropertyRunner(new FakeClock(stepNs: 5_000_001)))->run(
            $this->definition(runs: 3, timeoutMs: 5),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::cover(condition: true, label: 'covered', minPercent: 1.0);
                Gen::draw(Gen::intBetween(1, 5));
            }),
        );

        Assert::instanceOf($result, DeadlineExceeded::class);
        Assert::same($result->exception->timeoutMs, 5);
        Assert::same($result->exception->elapsedMs, 5_000_001 / 1e6);
        Assert::same($result->exception->arguments, ['value' => 7, 'draw#1' => 5]);
        Assert::same(Classify::flushRequirements(), []);
    }

    public function deadlineBoundaryEqualElapsedPasses(): void
    {
        // Every run takes exactly the 5ms deadline: strictly-greater must pass.
        $result = (new PropertyRunner(new FakeClock(stepNs: 5_000_000)))->run(
            $this->definition(runs: 2, timeoutMs: 5),
            new CallableTrialExecutor(static function (int $value): void {}),
        );

        Assert::instanceOf($result, Passed::class);
    }

    public function exampleDeadlineReportsNamedArguments(): void
    {
        $result = (new PropertyRunner(new FakeClock(stepNs: 5_000_001)))->run(
            $this->definition(runs: 1, timeoutMs: 5, examples: [[999]]),
            new CallableTrialExecutor(static function (int $value): void {}),
        );

        Assert::instanceOf($result, DeadlineExceeded::class);
        Assert::same($result->exception->elapsedMs, 5_000_001 / 1e6);
        Assert::same($result->exception->arguments, ['value' => 999]);
    }

    public function examplesAndRunsMeasureElapsedFromTheirOwnStart(): void
    {
        // The clock keeps advancing across examples and runs; each 2ms body
        // stays under the 5ms deadline only when elapsed is measured from the
        // run's own start.
        $listener = new CollectingListener();

        $result = (new PropertyRunner(new FakeClock(stepNs: 2_000_000)))->run(
            $this->definition(runs: 1, timeoutMs: 5, examples: [[1], [2]]),
            new CallableTrialExecutor(static function (int $value): void {}),
            [$listener],
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($listener->ofType(RunPassed::class)[0]->elapsedNs, 2_000_000);
    }

    public function exampleDeadlineBoundaryEqualElapsedPasses(): void
    {
        $result = (new PropertyRunner(new FakeClock(stepNs: 5_000_000)))->run(
            $this->definition(runs: 1, timeoutMs: 5, examples: [[1]]),
            new CallableTrialExecutor(static function (int $value): void {}),
        );

        Assert::instanceOf($result, Passed::class);
    }

    public function missingTimeoutDisablesTheExampleDeadline(): void
    {
        $result = (new PropertyRunner(new FakeClock(stepNs: 1_000)))->run(
            $this->definition(runs: 1, examples: [[1]]),
            new CallableTrialExecutor(static function (int $value): void {}),
        );

        Assert::instanceOf($result, Passed::class);
    }

    public function failingExampleWithoutTimeoutReportsTheFailure(): void
    {
        $result = (new PropertyRunner(new FakeClock(stepNs: 0)))->run(
            $this->definition(runs: 1, examples: [[999]]),
            new CallableTrialExecutor(static function (int $value): void {
                throw new \RuntimeException('example fails');
            }),
        );

        Assert::instanceOf($result, ExampleFailed::class);
        Assert::same($result->exception->getIndex(), 0);
        Assert::same($result->exception->getArguments(), [999]);
    }

    public function exampleLifecycleEventsCarryTheFailure(): void
    {
        $listener = new CollectingListener();

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1, examples: [[1], [999]]),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value > 10) {
                    throw new \RuntimeException('example input escaped the domain');
                }
            }),
            [$listener],
        );

        Assert::instanceOf($result, ExampleFailed::class);
        Assert::same($listener->shapes(), [
            'PropertyStarted',
            'ExampleStarted',
            'ExampleFinished',
            'ExampleStarted',
            'ExampleFinished',
            'PropertyFinished',
        ]);

        $started = $listener->ofType(ExampleStarted::class);
        Assert::same($started[0]->index, 0);
        Assert::same($started[0]->arguments, [1]);
        Assert::same($started[1]->index, 1);
        Assert::same($started[1]->arguments, [999]);

        $finished = $listener->ofType(ExampleFinished::class);
        Assert::null($finished[0]->failure);
        Assert::instanceOf($finished[1]->failure, \RuntimeException::class);
        Assert::same($finished[1]->failure->getMessage(), 'example input escaped the domain');
    }

    public function exampleBodyMayDraw(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1, examples: [[5]]),
            new CallableTrialExecutor(static function (int $value): void {
                Gen::draw(Gen::intBetween(1, 3));
            }),
        );

        Assert::instanceOf($result, Passed::class);
    }

    public function abortedExampleRunLeavesNoStaleLabels(): void
    {
        Classify::label('stale');

        $executor = new class implements TrialExecutor {
            #[\Override]
            public function execute(array $arguments): TrialOutcome
            {
                throw new \RuntimeException('infrastructure failure');
            }
        };

        $caught = false;

        try {
            (new PropertyRunner())->run($this->definition(runs: 1, examples: [[1]]), $executor);
        } catch (\RuntimeException) {
            $caught = true;
        }

        DrawContext::disarm();

        Assert::true($caught);
        // beginRun cleared the stale label before the aborted body executed.
        Assert::same(Classify::flushRun(), []);
    }

    #[ExpectException(\RuntimeException::class)]
    public function failingExampleDisarmsTheDrawContext(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1, examples: [[999]]),
            new CallableTrialExecutor(static function (int $value): void {
                throw new \RuntimeException('example fails');
            }),
        );

        Assert::instanceOf($result, ExampleFailed::class);
        // The early example-failure return must leave the context disarmed.
        Gen::draw(Gen::int());
    }

    public function failingExampleDrainsItsRunLabels(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1, examples: [[999]]),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::label('from-example');

                throw new \RuntimeException('example fails');
            }),
        );

        Assert::instanceOf($result, ExampleFailed::class);
        Assert::same(Classify::flushRun(), []);
    }

    public function unmetCoverageWithZeroOccurrencesReportsTheExactMessage(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(runs: 10),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::cover($value < 0, 'never', 1.0);
            }),
        );

        Assert::instanceOf($result, CoverageFailed::class);
        Assert::same(
            $result->exception->getMessage(),
            'Property "property" coverage not met: "never" 0.0% < required 1.0% (0/10)',
        );
    }

    public function coverageAtExactlyTheRequiredPercentagePasses(): void
    {
        $i = 0;

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 10),
            new CallableTrialExecutor(static function (int $value) use (&$i): void {
                Classify::cover($i % 2 === 0, 'half', 50.0);
                ++$i;
            }),
        );

        Assert::instanceOf($result, Passed::class);
    }

    public function partiallyMetCoverageReportsTheExactMessage(): void
    {
        $i = 0;

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 10),
            new CallableTrialExecutor(static function (int $value) use (&$i): void {
                Classify::cover($i % 5 === 0, 'fifth', 90.0);
                ++$i;
            }),
        );

        Assert::instanceOf($result, CoverageFailed::class);
        Assert::same(
            $result->exception->getMessage(),
            'Property "property" coverage not met: "fifth" 20.0% < required 90.0% (2/10)',
        );
    }

    public function passingRunReportsDrawsToListeners(): void
    {
        $listener = new CollectingListener();

        $result = (new PropertyRunner())->run(
            $this->definition(runs: 1),
            new CallableTrialExecutor(static function (int $value): void {
                Gen::draw(Gen::intBetween(1, 5));
            }),
            [$listener],
        );

        Assert::instanceOf($result, Passed::class);

        $passed = $listener->ofType(RunPassed::class)[0];
        Assert::same($passed->arguments, ['value' => 7]);
        Assert::same($passed->draws, ['draw#1' => 5]);
    }

    /**
     * @param list<list<mixed>> $examples
     */
    private function definition(
        int $runs,
        ?int $maxDiscards = null,
        ?int $timeoutMs = null,
        ?int $budgetMs = null,
        array $examples = [],
    ): PropertyDefinition {
        return new PropertyDefinition(
            id: 'lifecycle::property',
            name: 'property',
            generators: ['value' => Gen::intBetween(0, 10)],
            parameterNames: ['value'],
            config: new PropertyConfig(
                runs: $runs,
                seed: 42,
                maxDiscards: $maxDiscards,
                timeoutMs: $timeoutMs,
                budgetMs: $budgetMs,
            ),
            examples: $examples,
        );
    }
}
