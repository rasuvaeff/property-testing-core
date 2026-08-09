<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Event\RunFailed;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\Event\ShrinkTried;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Tests\Support\ChainArbitrary;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Pins the shrink descent in exact numbers: the per-parameter in-place
 * replacement, the accepted-step and trial accounting mirrored in the shrink
 * events, the maxShrinks cap semantics, and the in-body draw tape walk. All
 * inputs are seed-pinned, so every expectation is a concrete value.
 */
#[Test]
#[Covers(PropertyRunner::class)]
final class PropertyRunnerShrinkTest
{
    public function shrinkLifecycleIsFullyReported(): void
    {
        $listener = new CollectingListener();

        $result = (new PropertyRunner())->run(
            $this->definition(['value' => Gen::intBetween(0, 10_000)], ['value']),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value >= 100) {
                    throw new \RuntimeException(sprintf('%d is not below 100', $value));
                }
            }),
            [$listener],
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->originalArguments, ['value' => 3989]);
        Assert::same($example->shrunkArguments, ['value' => 100]);
        Assert::same($example->shrinkSteps, 9);
        Assert::same($example->shrinkTrials, 39);
        Assert::same($example->runsBeforeFailure, 0);
        // The reported failure comes from the minimised run, not the original.
        Assert::same($example->failure?->getMessage(), '100 is not below 100');

        $failed = $listener->ofType(RunFailed::class);
        Assert::same(count($failed), 1);
        Assert::same($failed[0]->attempt, 1);
        Assert::same($failed[0]->arguments, ['value' => 3989]);
        Assert::instanceOf($failed[0]->failure, \RuntimeException::class);

        Assert::same(count($listener->ofType(ShrinkTried::class)), 39);

        $accepted = $listener->ofType(ShrinkAccepted::class);
        Assert::same(count($accepted), 9);
        Assert::same(
            array_map(static fn(ShrinkAccepted $event): int => $event->step, $accepted),
            [1, 2, 3, 4, 5, 6, 7, 8, 9],
        );
    }

    public function shrinkingReplacesOneParameterInPlace(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(
                ['a' => Gen::intBetween(0, 10_000), 'b' => Gen::intBetween(0, 10_000)],
                ['a', 'b'],
            ),
            new CallableTrialExecutor(static function (int $a, int $b): void {
                if ($a + $b >= 1000) {
                    throw new \RuntimeException('sum too big');
                }
            }),
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->originalArguments, ['a' => 3989, 'b' => 7638]);
        Assert::same($example->shrunkArguments, ['a' => 0, 'b' => 1000]);
        Assert::same($example->shrinkSteps, 8);
        Assert::same($example->shrinkTrials, 44);
    }

    public function maxShrinksZeroDisablesShrinking(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(['value' => Gen::intBetween(0, 10_000)], ['value'], maxShrinks: 0),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value >= 100) {
                    throw new \RuntimeException('too big');
                }
            }),
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->shrunkArguments, ['value' => 3989]);
        Assert::same($example->shrunkArguments, $example->originalArguments);
        Assert::same($example->shrinkSteps, 0);
        Assert::same($example->shrinkTrials, 0);
    }

    public function maxShrinksCapsAcceptedSteps(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(['value' => Gen::intBetween(0, 10_000)], ['value'], maxShrinks: 2),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value >= 100) {
                    throw new \RuntimeException('too big');
                }
            }),
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->shrinkSteps, 2);
        Assert::same($example->shrunkArguments, ['value' => 998]);
        Assert::same($example->shrinkTrials, 4);
    }

    public function unboundedShrinkIsNotCappedByTheDrawLimit(): void
    {
        // Without in-body draws there is no implicit step cap: a 2000-node
        // chain descends all the way to its leaf.
        $result = (new PropertyRunner())->run(
            $this->definition(['value' => new ChainArbitrary(2000)], ['value'], runs: 1),
            new CallableTrialExecutor(static function (int $value): void {
                throw new \RuntimeException('always fails');
            }),
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->shrunkArguments, ['value' => 0]);
        Assert::same($example->shrinkSteps, 2000);
        Assert::same($example->shrinkTrials, 2000);
    }

    public function inBodyDrawsShrinkThroughTheTape(): void
    {
        $listener = new CollectingListener();

        $result = (new PropertyRunner())->run(
            $this->definition([], [], runs: 50),
            new CallableTrialExecutor(static function (): void {
                $draw = Gen::draw(Gen::intBetween(0, 1000));

                if ($draw >= 10) {
                    throw new \RuntimeException(sprintf('draw %d too big', $draw));
                }
            }),
            [$listener],
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->originalArguments, ['draw#1' => 359]);
        Assert::same($example->shrunkArguments, ['draw#1' => 10]);
        Assert::same($example->shrinkSteps, 7);
        Assert::same($example->shrinkTrials, 22);

        // Tape shrink trials report the single position as `draw#1` throughout.
        $tried = $listener->ofType(ShrinkTried::class);
        Assert::same(count($tried), 22);
        Assert::same(
            array_values(array_unique(array_map(static fn(ShrinkTried $event): string => $event->parameter, $tried))),
            ['draw#1'],
        );

        $accepted = $listener->ofType(ShrinkAccepted::class);
        Assert::same(
            array_map(static fn(ShrinkAccepted $event): int => $event->step, $accepted),
            [1, 2, 3, 4, 5, 6, 7],
        );
        Assert::same(
            array_values(array_unique(array_map(static fn(ShrinkAccepted $event): string => $event->parameter, $accepted))),
            ['draw#1'],
        );
    }

    public function eachTapePositionShrinksInPlace(): void
    {
        $listener = new CollectingListener();

        $result = (new PropertyRunner())->run(
            $this->definition([], [], runs: 50),
            new CallableTrialExecutor(static function (): void {
                $first = Gen::draw(Gen::intBetween(0, 1000));
                $second = Gen::draw(Gen::intBetween(0, 1000));

                if ($first + $second >= 100) {
                    throw new \RuntimeException('sum too big');
                }
            }),
            [$listener],
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->originalArguments, ['draw#1' => 359, 'draw#2' => 355]);
        Assert::same($example->shrunkArguments, ['draw#1' => 0, 'draw#2' => 100]);
        Assert::same($example->shrinkSteps, 5);
        Assert::same($example->shrinkTrials, 23);

        $accepted = $listener->ofType(ShrinkAccepted::class);
        Assert::same(
            array_map(static fn(ShrinkAccepted $event): string => $event->parameter, $accepted),
            ['draw#1', 'draw#2', 'draw#2', 'draw#2', 'draw#2'],
        );
        Assert::same(
            array_map(static fn(ShrinkAccepted $event): int => $event->step, $accepted),
            [1, 2, 3, 4, 5],
        );
    }

    public function counterexampleMergesParametersAndDraws(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(['value' => Gen::intBetween(0, 10_000)], ['value']),
            new CallableTrialExecutor(static function (int $value): void {
                Gen::draw(Gen::intBetween(0, 1000));

                if ($value >= 100) {
                    throw new \RuntimeException('value too big');
                }
            }),
        );

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->originalArguments, ['value' => 3989, 'draw#1' => 355]);
        Assert::same($example->shrunkArguments, ['value' => 100, 'draw#1' => 0]);
        Assert::same($example->shrinkSteps, 10);
        Assert::same($example->shrinkTrials, 40);
    }

    /**
     * @param array<string, ArbitraryInterface> $generators
     * @param list<string> $parameterNames
     */
    private function definition(
        array $generators,
        array $parameterNames,
        int $runs = 200,
        ?int $maxShrinks = null,
    ): PropertyDefinition {
        return new PropertyDefinition(
            id: 'shrink::property',
            name: 'property',
            generators: $generators,
            parameterNames: $parameterNames,
            config: new PropertyConfig(runs: $runs, seed: 42, maxShrinks: $maxShrinks),
        );
    }
}
