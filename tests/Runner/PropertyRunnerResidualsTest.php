<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Event\CorpusReplayed;
use Rasuvaeff\PropertyTesting\Event\TargetImproved;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\Internal\PhaseCounters;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\GenerationFailed;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\TimeBudgetExceeded;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Target;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Rasuvaeff\PropertyTesting\Tests\Support\FakeClock;
use Rasuvaeff\PropertyTesting\Tests\Support\RecordingCorpus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Runner behaviour the broader suites reach without pinning: per-run state
 * an example leaves behind, the seed-replay event, the search phase's own
 * exits, a candidate equal to its parent followed by a smaller one, the
 * discard cap's saturation, and the absence of PHP warnings.
 */
#[Test]
#[Covers(PropertyRunner::class)]
#[Covers(PhaseCounters::class)]
final class PropertyRunnerResidualsTest
{
    public function intersectionsCountEverySortedPairOnce(): void
    {
        $counters = new PhaseCounters(null, null);

        $counters->passed([], ['shape' => ['c', 'a', 'b']]);
        $counters->passed([], ['shape' => ['c', 'a', 'b']]);
        $counters->passed([], ['shape' => ['b', 'a']]);

        $statistics = $counters->statistics([]);

        Assert::same($statistics->checks, 3);
        Assert::same($statistics->tables, ['shape' => ['c' => 2, 'a' => 3, 'b' => 3]]);
        Assert::same($statistics->intersections, ['shape' => ['a & b' => 3, 'a & c' => 2, 'b & c' => 2]]);
    }

    public function anExampleTableDoesNotLeakIntoTheFirstRandomRun(): void
    {
        $result = $this->runExampled(static function (int $x): void {
            if ($x === -1) {
                Classify::tabulate('origin', 'example');
            }
        });

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->checks, 5);
        Assert::same($result->statistics->tables, []);
    }

    public function anExampleTargetScoreDoesNotLeakIntoTheFirstRandomRun(): void
    {
        $listener = new CollectingListener();

        $result = $this->runExampled(static function (int $x): void {
            if ($x === -1) {
                Target::maximize('example', 100);
            }
        }, $listener);

        Assert::instanceOf($result, Passed::class);
        Assert::same(count($listener->ofType(TargetImproved::class)), 0);
    }

    public function aSeedEntryIsAnnouncedAsASeedReplay(): void
    {
        $listener = new CollectingListener();

        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'residuals::seed-replay',
                name: 'seed replay',
                generators: ['x' => Gen::intBetween(0, 10)],
                parameterNames: ['x'],
                config: new PropertyConfig(runs: 3, seed: 1),
            ),
            new CallableTrialExecutor(static function (int $x): void {}),
            [$listener],
            new RecordingCorpus([CorpusEntry::seed(7, runsBeforeFailure: 0)]),
        );

        Assert::instanceOf($result, Passed::class);
        $replayed = $listener->ofType(CorpusReplayed::class);
        Assert::same(count($replayed), 1);
        Assert::false($replayed[0]->isValues);
        Assert::same($replayed[0]->arguments, []);
        Assert::same($replayed[0]->seed, 7);
    }

    public function aRunEmitsNoPhpWarnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $result = (new PropertyRunner())->run(
                new PropertyDefinition(
                    id: 'residuals::warnings',
                    name: 'warnings',
                    generators: ['x' => Gen::intBetween(0, 100)],
                    parameterNames: ['x'],
                    config: new PropertyConfig(runs: 50, seed: 42),
                ),
                new CallableTrialExecutor(static function (int $x): void {
                    if ($x > 10) {
                        throw new \RuntimeException('too big');
                    }
                }),
            );
        } finally {
            restore_error_handler();
        }

        Assert::instanceOf($result, Falsified::class);
        Assert::same($warnings, []);
    }

    public function theDiscardCapSaturatesInsteadOfOverflowing(): void
    {
        $runs = intdiv(PHP_INT_MAX, 10) + 1;

        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'residuals::saturation',
                name: 'saturation',
                generators: ['flag' => Gen::bool()],
                parameterNames: ['flag'],
                config: new PropertyConfig(runs: $runs, seed: 42, exhaustive: true),
            ),
            new CallableTrialExecutor(static function (bool $flag): void {}),
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->checks, 2);
        Assert::same($result->statistics->domainSize, 2);
    }

    public function theTimeBudgetAlsoEndsTheSearchPhase(): void
    {
        $result = (new PropertyRunner(new FakeClock(stepNs: 1_000_000)))->run(
            new PropertyDefinition(
                id: 'residuals::search-budget',
                name: 'search budget',
                generators: ['x' => Gen::intBetween(0, 1000)],
                parameterNames: ['x'],
                config: new PropertyConfig(runs: 2, seed: 42, budgetMs: 12, searchRuns: 100),
            ),
            new CallableTrialExecutor(static function (int $x): void {
                Target::maximize('x', $x);
            }),
        );

        Assert::instanceOf($result, TimeBudgetExceeded::class);
        Assert::same($result->exception->successfulRuns, 4);
        Assert::same($result->exception->requiredRuns, 2);
        Assert::same($result->statistics->checks, 4);
    }

    public function anExhaustedGeneratorEndsTheSearchPhaseCleanly(): void
    {
        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'residuals::search-exhausted',
                name: 'search exhausted',
                generators: ['x' => $this->exhaustingAfter(2)],
                parameterNames: ['x'],
                config: new PropertyConfig(runs: 2, seed: 42, searchRuns: 10),
            ),
            new CallableTrialExecutor(static function (int $x): void {
                Classify::cover(condition: true, label: 'any', minPercent: 10.0);
                Target::maximize('x', $x);
            }),
        );

        Assert::instanceOf($result, GenerationFailed::class);
        Assert::same($result->exception->attempts, 3);
        Assert::same(Classify::flushRequirements(), []);
    }

    public function aCandidateEqualToItsParentIsSkippedNotTheEndOfTheSearch(): void
    {
        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'residuals::equal-candidate',
                name: 'equal candidate',
                generators: ['x' => $this->tenWithAnEqualCandidateFirst()],
                parameterNames: ['x'],
                config: new PropertyConfig(runs: 5, seed: 42),
            ),
            new CallableTrialExecutor(static function (int $x): void {
                throw new \RuntimeException('always');
            }),
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->shrunkArguments, ['x' => 3]);
        Assert::same($example->shrinkSteps, 1);
        Assert::same($example->shrinkTrials, 1);
        Assert::same($example->path, 'x:1');
    }

    public function aTapeCandidateEqualToItsParentIsSkippedNotTheEndOfTheWalk(): void
    {
        $arbitrary = $this->tenWithAnEqualCandidateFirst();

        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'residuals::equal-tape-candidate',
                name: 'equal tape candidate',
                generators: [],
                parameterNames: [],
                config: new PropertyConfig(runs: 5, seed: 42),
            ),
            new CallableTrialExecutor(static function () use ($arbitrary): void {
                Gen::draw($arbitrary);

                throw new \RuntimeException('always');
            }),
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->shrunkArguments, ['draw#1' => 3]);
        Assert::same($example->shrinkSteps, 1);
        Assert::same($example->shrinkTrials, 1);
    }

    private function runExampled(\Closure $body, ?CollectingListener $listener = null): PropertyResult
    {
        return (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'residuals::example-leak',
                name: 'example leak',
                generators: ['x' => Gen::intBetween(0, 10)],
                parameterNames: ['x'],
                config: new PropertyConfig(runs: 5, seed: 42),
                examples: [[-1]],
            ),
            new CallableTrialExecutor($body),
            $listener instanceof \Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener ? [$listener] : [],
        );
    }

    private function exhaustingAfter(int $successes): ArbitraryInterface
    {
        return new class ($successes) implements ArbitraryInterface {
            private int $calls = 0;

            public function __construct(
                private readonly int $successes,
            ) {}

            #[\Override]
            public function generate(Random $random): Shrinkable
            {
                ++$this->calls;

                if ($this->calls > $this->successes) {
                    throw new GenerationExhaustedException('exhausting', $this->calls, 'no more values');
                }

                return Shrinkable::leaf($this->calls);
            }
        };
    }

    private function tenWithAnEqualCandidateFirst(): ArbitraryInterface
    {
        return new class implements ArbitraryInterface {
            #[\Override]
            public function generate(Random $random): Shrinkable
            {
                return Shrinkable::of(10, static function (): \Generator {
                    yield Shrinkable::leaf(10);
                    yield Shrinkable::leaf(3);
                });
            }
        };
    }
}
