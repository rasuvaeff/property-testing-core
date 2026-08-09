<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\ExampleViolationException;
use Rasuvaeff\PropertyTesting\GaveUpException;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\CoverageFailed;
use Rasuvaeff\PropertyTesting\Runner\ExampleFailed;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\GenerationFailed;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The engine driven directly, without the Testo adapter: a hand-built
 * definition, the callable executor and structured results. The deep loop
 * semantics (events, corpus, deadline, budget, draws) stay characterised
 * through the interceptor suites; this pins the standalone surface the split
 * extracts into property-testing-core.
 */
#[Test]
#[Covers(PropertyRunner::class)]
#[Covers(Passed::class)]
#[Covers(Falsified::class)]
#[Covers(GaveUp::class)]
#[Covers(CoverageFailed::class)]
#[Covers(GenerationFailed::class)]
#[Covers(ExampleFailed::class)]
#[Covers(RunStatistics::class)]
final class PropertyRunnerTest
{
    public function passedCarriesTheRunStatistics(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(runs: 25),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::when($value >= 0, 'non-negative');
            }),
        );

        Assert::instanceOf($result, Passed::class);
        Assert::null($result->failure());
        Assert::same($result->statistics->checks, 25);
        Assert::same($result->statistics->attempts, 25);
        Assert::same($result->statistics->discards, 0);
        Assert::same($result->statistics->classifications, ['non-negative' => 25]);
    }

    public function falsificationShrinksToTheMinimalCounterexample(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(runs: 200),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value >= 100) {
                    throw new \RuntimeException(sprintf('%d is not below 100', $value));
                }
            }),
        );

        Assert::instanceOf($result, Falsified::class);

        $example = $result->counterExample();
        Assert::same($example->seed, 42);
        Assert::same($example->shrunkArguments, ['value' => 100]);
        Assert::instanceOf($example->failure, \RuntimeException::class);
        Assert::same($result->failure(), $result->exception);
        Assert::same($result->exception->getCounterExample(), $example);
    }

    public function exhaustedDiscardBudgetGivesUp(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(runs: 5, maxDiscards: 3),
            new CallableTrialExecutor(static fn() => Assume::that(condition: false)),
        );

        Assert::instanceOf($result, GaveUp::class);
        Assert::instanceOf($result->failure(), GaveUpException::class);
        Assert::same($result->exception->discardedRuns, 4);
        Assert::same($result->exception->maxDiscards, 3);
        Assert::same($result->statistics->checks, 0);
        Assert::same($result->statistics->discards, 4);
    }

    public function unmetCoverageFailsThePassingProperty(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(runs: 10),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::cover($value < 0, 'negative', 99.0);
            }),
        );

        Assert::instanceOf($result, CoverageFailed::class);
        Assert::string($result->exception->getMessage())->contains('coverage not met');
        Assert::same($result->failure(), $result->exception);
        Assert::same($result->statistics->checks, 10);
    }

    public function exhaustedGeneratorReportsGenerationFailed(): void
    {
        $definition = new PropertyDefinition(
            id: 'standalone::neverGenerates',
            name: 'neverGenerates',
            generators: ['value' => Gen::filter(Gen::int(), static fn(): bool => false)],
            parameterNames: ['value'],
            config: new PropertyConfig(runs: 5, seed: 42),
        );

        $result = (new PropertyRunner())->run(
            $definition,
            new CallableTrialExecutor(static function (): void {}),
        );

        Assert::instanceOf($result, GenerationFailed::class);
        Assert::same($result->failure(), $result->exception);
    }

    public function failingExampleShortCircuitsTheRandomPhase(): void
    {
        $bodies = 0;
        $definition = new PropertyDefinition(
            id: 'standalone::examplesFirst',
            name: 'examplesFirst',
            generators: ['value' => Gen::intBetween(0, 10)],
            parameterNames: ['value'],
            config: new PropertyConfig(runs: 50, seed: 42),
            examples: [[999]],
        );

        $result = (new PropertyRunner())->run(
            $definition,
            new CallableTrialExecutor(static function (int $value) use (&$bodies): void {
                ++$bodies;

                if ($value > 10) {
                    throw new \RuntimeException('example input escaped the domain');
                }
            }),
        );

        Assert::instanceOf($result, ExampleFailed::class);
        Assert::instanceOf($result->failure(), ExampleViolationException::class);
        Assert::same($result->exception->getIndex(), 0);
        Assert::same($result->exception->getArguments(), [999]);
        Assert::same($bodies, 1);
    }

    public function falsificationDrainsTheCoverageRequirements(): void
    {
        $result = (new PropertyRunner())->run(
            $this->definition(runs: 10),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::cover($value < 0, 'negative', 99.0);

                throw new \RuntimeException('always fails');
            }),
        );

        Assert::instanceOf($result, Falsified::class);
        // Uniform with every other exit path: no armed requirements survive
        // the run for a standalone caller to trip over.
        Assert::same(Classify::flushRequirements(), []);
    }

    public function fixedSeedReproducesTheSameCounterexample(): void
    {
        $body = static function (int $value): void {
            if ($value > 5_000) {
                throw new \RuntimeException('too big');
            }
        };

        $first = (new PropertyRunner())->run($this->definition(runs: 100), new CallableTrialExecutor($body));
        $second = (new PropertyRunner())->run($this->definition(runs: 100), new CallableTrialExecutor($body));

        Assert::instanceOf($first, Falsified::class);
        Assert::instanceOf($second, Falsified::class);
        \assert($first instanceof Falsified && $second instanceof Falsified);
        Assert::same($first->counterExample()->originalArguments, $second->counterExample()->originalArguments);
        Assert::same($first->counterExample()->shrunkArguments, $second->counterExample()->shrunkArguments);
    }

    private function definition(int $runs, ?int $maxDiscards = null): PropertyDefinition
    {
        return new PropertyDefinition(
            id: 'standalone::property',
            name: 'property',
            generators: ['value' => Gen::intBetween(0, 10_000)],
            parameterNames: ['value'],
            config: new PropertyConfig(runs: $runs, seed: 42, maxDiscards: $maxDiscards),
        );
    }
}
