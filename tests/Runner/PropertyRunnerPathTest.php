<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\Event\ShrinkTried;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PathFailed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Rasuvaeff\PropertyTesting\Tests\Support\RecordingCorpus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Pins the recorded shrink path and its replay in exact numbers. Every input
 * here is seed-pinned, so the paths below are golden values: a descent that
 * records a different path after an upgrade replays a different bug than the
 * one the developer was chasing.
 *
 * The four ways a path stops applying are each provoked by a real change to
 * the property — a shorter enumeration, a renamed parameter, a non-injective
 * map, a moved threshold — rather than by a hand-written stub, because that is
 * how they will reach a user.
 */
#[Test]
#[Covers(PropertyRunner::class)]
final class PropertyRunnerPathTest
{
    /**
     * The nine accepted steps of the same descent
     * {@see PropertyRunnerShrinkTest::shrinkLifecycleIsFullyReported()} pins at
     * 39 tried candidates.
     */
    private const string INT_PATH = 'value:1/value:1/value:1/value:1/value:1/value:3/value:4/value:5/value:6';

    private const string DRAW_PATH = 'value:1/draw#1:0/value:1/value:1/value:1/value:1/value:3/value:4/value:5/value:6';

    public function aDescentIsRecordedAsAReplayablePath(): void
    {
        $example = $this->falsify($this->belowHundred());

        Assert::same($example->path, self::INT_PATH);
        Assert::same($example->shrinkSteps, 9);
        Assert::same($example->shrinkTrials, 39);
        Assert::same($example->shrunkArguments, ['value' => 100]);
    }

    public function replayingThePathCostsOneTrialPerStepInsteadOfOnePerCandidate(): void
    {
        $example = $this->falsify($this->belowHundred(), path: self::INT_PATH);

        Assert::same($example->shrunkArguments, ['value' => 100]);
        Assert::same($example->failure?->getMessage(), '100 is not below 100');
        Assert::same($example->shrinkSteps, 9);
        // The saving the feature exists for: 39 candidates tried in the search,
        // exactly the nine accepted ones re-run in the replay.
        Assert::same($example->shrinkTrials, 9);
        // Reported back unchanged, so a replay's own message is replayable too.
        Assert::same($example->path, self::INT_PATH);
    }

    public function inBodyDrawsReplayUnderTheirPseudoNames(): void
    {
        $example = $this->falsify($this->belowHundredAfterADraw());

        Assert::same($example->path, self::DRAW_PATH);
        Assert::same($example->shrinkTrials, 40);

        $replayed = $this->falsify($this->belowHundredAfterADraw(), path: self::DRAW_PATH);

        Assert::same($replayed->shrunkArguments, ['value' => 100, 'draw#1' => 0]);
        Assert::same($replayed->shrinkSteps, 10);
        Assert::same($replayed->shrinkTrials, 10);
    }

    /**
     * Two parameters and two draws, because one of each cannot tell a replay
     * that replaces a node *in place* from one that throws the rest of the run
     * away: with a single node the two are the same array.
     */
    public function aReplayReplacesOneNodeAndKeepsTheOthers(): void
    {
        $sumBelowThousand = static function (int $a, int $b): void {
            if ($a + $b >= 1_000) {
                throw new \RuntimeException('sum too big');
            }
        };
        $generators = ['a' => Gen::intBetween(0, 10_000), 'b' => Gen::intBetween(0, 10_000)];
        $path = 'a:0/b:1/b:1/b:2/b:2/b:4/b:7/b:9';

        $recorded = (new PropertyRunner())->run(
            $this->twoParameterDefinition($generators),
            new CallableTrialExecutor($sumBelowThousand),
        );

        Assert::instanceOf($recorded, Falsified::class);
        Assert::same($recorded->counterExample()->path, $path);
        Assert::same($recorded->counterExample()->shrinkTrials, 44);

        $replayed = (new PropertyRunner())->run(
            $this->twoParameterDefinition($generators, $path),
            new CallableTrialExecutor($sumBelowThousand),
        );

        Assert::instanceOf($replayed, Falsified::class);
        Assert::same($replayed->counterExample()->shrunkArguments, ['a' => 0, 'b' => 1_000]);
        Assert::same($replayed->counterExample()->shrinkTrials, 8);
    }

    public function aReplayReplacesOneTapePositionAndKeepsTheOthers(): void
    {
        $sumOfTwoDrawsBelowHundred = static function (): void {
            $first = Gen::draw(Gen::intBetween(0, 1_000));
            $second = Gen::draw(Gen::intBetween(0, 1_000));

            if ($first + $second >= 100) {
                throw new \RuntimeException('sum too big');
            }
        };
        $path = 'draw#1:0/draw#2:1/draw#2:2/draw#2:2/draw#2:6';

        $recorded = (new PropertyRunner())->run(
            $this->drawOnlyDefinition(),
            new CallableTrialExecutor($sumOfTwoDrawsBelowHundred),
        );

        Assert::instanceOf($recorded, Falsified::class);
        Assert::same($recorded->counterExample()->path, $path);
        Assert::same($recorded->counterExample()->shrinkTrials, 23);

        $replayed = (new PropertyRunner())->run(
            $this->drawOnlyDefinition($path),
            new CallableTrialExecutor($sumOfTwoDrawsBelowHundred),
        );

        Assert::instanceOf($replayed, Falsified::class);
        Assert::same($replayed->counterExample()->shrunkArguments, ['draw#1' => 0, 'draw#2' => 100]);
        Assert::same($replayed->counterExample()->shrinkTrials, 5);
    }

    public function aReplayReportsTheSameShrinkEventsAsTheStepsItFollowed(): void
    {
        $listener = new CollectingListener();
        $this->falsify($this->belowHundred(), path: self::INT_PATH, listener: $listener);

        // One tried candidate per step, all of them accepted — the replay never
        // looks at an alternative.
        Assert::same(count($listener->ofType(ShrinkTried::class)), 9);
        Assert::same(
            array_map(static fn(ShrinkTried $event): bool => $event->accepted, $listener->ofType(ShrinkTried::class)),
            array_fill(0, 9, value: true),
        );
        Assert::same(
            array_map(static fn(ShrinkAccepted $event): int => $event->step, $listener->ofType(ShrinkAccepted::class)),
            [1, 2, 3, 4, 5, 6, 7, 8, 9],
        );
    }

    /**
     * A corpus seed entry replays a different run from the one the path was
     * recorded on, so the path must not reach it: applied there it would report
     * "path no longer applies" over the very regression the corpus exists to
     * surface.
     */
    public function aPinnedPathDoesNotReachTheCorpusReplay(): void
    {
        $corpus = new RecordingCorpus([CorpusEntry::seed(7)]);

        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'path::property',
                name: 'property',
                generators: ['value' => Gen::intBetween(0, 10_000)],
                parameterNames: ['value'],
                config: new PropertyConfig(runs: 200, seed: 42, path: self::INT_PATH),
            ),
            new CallableTrialExecutor($this->belowHundred()),
            [],
            $corpus,
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->seed, 7);
        // Searched, not followed: the recorded seed's descent tries candidates
        // it rejects, which a replay never does.
        Assert::true($example->shrinkTrials > $example->shrinkSteps);
        Assert::same($corpus->pruned, []);
    }

    public function aDescentCutShortStillReportsThePathItTook(): void
    {
        $example = $this->falsify($this->belowHundred(), maxShrinks: 2);

        Assert::same($example->path, 'value:1/value:1');
        Assert::same($example->shrinkSteps, 2);
    }

    public function aDescentThatNeverRanReportsAnEmptyPath(): void
    {
        $example = $this->falsify($this->belowHundred(), shrink: ShrinkMode::Off);

        Assert::same($example->path, '');
        Assert::same($example->shrinkSteps, 0);
    }

    /**
     * @param \Closure(int): void $body
     */
    #[DataProvider('stalePathProvider')]
    public function aStalePathIsReportedInsteadOfSearchedAround(
        string $path,
        \Closure $body,
        ArbitraryInterface $generator,
        int $step,
        string $segment,
        string $reason,
    ): void {
        $result = $this->run($body, path: $path, generator: $generator);

        Assert::instanceOf($result, PathFailed::class);
        Assert::same($result->exception->getPath(), $path);
        Assert::same($result->exception->getStep(), $step);
        Assert::same($result->exception->getSegment(), $segment);
        Assert::string($result->exception->getMessage())->contains($reason);
    }

    /**
     * @return iterable<string, array{string, \Closure(int): void, ArbitraryInterface, int, string, string}>
     */
    public static function stalePathProvider(): iterable
    {
        $belowHundred = static function (int $value): void {
            if ($value >= 100) {
                throw new \RuntimeException(sprintf('%d is not below 100', $value));
            }
        };

        yield 'renamed parameter' => [
            'renamed:0',
            $belowHundred,
            Gen::intBetween(0, 10_000),
            1,
            'renamed:0',
            'names something this run does not have',
        ];

        // A path recorded against a body that drew, replayed against one that
        // does not: the tape position it names is not there.
        yield 'draw that no longer happens' => [
            'draw#1:0',
            $belowHundred,
            Gen::intBetween(0, 10_000),
            1,
            'draw#1:0',
            'names something this run does not have',
        ];

        yield 'shorter enumeration' => [
            'value:99',
            $belowHundred,
            Gen::intBetween(0, 10_000),
            1,
            'value:99',
            'has no such candidate any more',
        ];

        // The generator now rounds to hundreds, so by the sixth step the
        // candidate the path names maps onto the value it would replace. Left
        // unchecked this step would re-run the identical input, fail again and
        // report a path that "applied" without minimising anything.
        yield 'candidate that no longer differs' => [
            self::INT_PATH,
            $belowHundred,
            Gen::map(Gen::intBetween(0, 10_000), static fn(int $value): int => intdiv($value, 100) * 100),
            6,
            'value:3',
            'no longer changes the value',
        ];

        // The threshold moved, so the first recorded step lands on an input
        // that passes: the recorded descent is not this property's descent.
        yield 'step that no longer falsifies' => [
            self::INT_PATH,
            static function (int $value): void {
                if ($value >= 3_000) {
                    throw new \RuntimeException('too big');
                }
            },
            Gen::intBetween(0, 10_000),
            1,
            'value:1',
            'no longer falsifies the property',
        ];
    }

    public function aReplayedPathSurvivesTheMachineReadableRepresentation(): void
    {
        $example = $this->falsify($this->belowHundred(), path: self::INT_PATH);

        Assert::same($example->toArray()['path'], self::INT_PATH);

        $decoded = json_decode($example->toJson(), associative: true, flags: JSON_THROW_ON_ERROR);
        Assert::same(is_array($decoded) ? $decoded['path'] : null, self::INT_PATH);
    }

    /**
     * @param array<string, ArbitraryInterface> $generators
     */
    private function twoParameterDefinition(array $generators, ?string $path = null): PropertyDefinition
    {
        return new PropertyDefinition(
            id: 'path::property',
            name: 'property',
            generators: $generators,
            parameterNames: ['a', 'b'],
            config: new PropertyConfig(runs: 200, seed: 42, path: $path),
        );
    }

    private function drawOnlyDefinition(?string $path = null): PropertyDefinition
    {
        return new PropertyDefinition(
            id: 'path::property',
            name: 'property',
            generators: [],
            parameterNames: [],
            config: new PropertyConfig(runs: 50, seed: 42, path: $path),
        );
    }

    /**
     * @return \Closure(int): void
     */
    private function belowHundred(): \Closure
    {
        return static function (int $value): void {
            if ($value >= 100) {
                throw new \RuntimeException(sprintf('%d is not below 100', $value));
            }
        };
    }

    /**
     * @return \Closure(int): void
     */
    private function belowHundredAfterADraw(): \Closure
    {
        return static function (int $value): void {
            Gen::draw(Gen::intBetween(0, 1_000));

            if ($value >= 100) {
                throw new \RuntimeException(sprintf('%d is not below 100', $value));
            }
        };
    }

    /**
     * @param \Closure(int): void $body
     */
    private function falsify(
        \Closure $body,
        ?string $path = null,
        ?int $maxShrinks = null,
        ?ShrinkMode $shrink = null,
        ?CollectingListener $listener = null,
    ): CounterExample {
        $result = $this->run($body, $path, $maxShrinks, $shrink, $listener);

        Assert::instanceOf($result, Falsified::class);

        return $result->counterExample();
    }

    /**
     * @param \Closure(int): void $body
     */
    private function run(
        \Closure $body,
        ?string $path = null,
        ?int $maxShrinks = null,
        ?ShrinkMode $shrink = null,
        ?CollectingListener $listener = null,
        ?ArbitraryInterface $generator = null,
    ): PropertyResult {
        return (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'path::property',
                name: 'property',
                generators: ['value' => $generator ?? Gen::intBetween(0, 10_000)],
                parameterNames: ['value'],
                config: new PropertyConfig(
                    runs: 200,
                    seed: 42,
                    maxShrinks: $maxShrinks,
                    shrink: $shrink,
                    path: $path,
                ),
            ),
            new CallableTrialExecutor($body),
            $listener instanceof CollectingListener ? [$listener] : [],
        );
    }
}
