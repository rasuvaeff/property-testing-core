<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\RegressionViolationException;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\CoverageFailed;
use Rasuvaeff\PropertyTesting\Runner\DeadlineExceeded;
use Rasuvaeff\PropertyTesting\Runner\ExampleFailed;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\GenerationFailed;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\RegressionFailed;
use Rasuvaeff\PropertyTesting\Runner\TimeBudgetExceeded;
use Rasuvaeff\PropertyTesting\Tests\Support\FakeClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The serialization contract of the structured results (evolution plan, stage
 * E): the engine adds no unserializable state of its own, so every outcome —
 * runner-built exception, counterexample, statistics — survives native
 * `serialize()` as long as the captured stack traces carry no argument values
 * (`zend.exception_ignore_args=1`, the production default). With argument
 * capture enabled the trace holds the executor's closures and serializability
 * becomes a property of the environment, not of the engine — the portable
 * machine format is {@see \Rasuvaeff\PropertyTesting\CounterExample::toArray()}.
 */
#[Test]
#[Covers(PropertyRunner::class)]
#[Covers(Passed::class)]
#[Covers(Falsified::class)]
#[Covers(GaveUp::class)]
#[Covers(CoverageFailed::class)]
#[Covers(GenerationFailed::class)]
#[Covers(ExampleFailed::class)]
#[Covers(DeadlineExceeded::class)]
#[Covers(TimeBudgetExceeded::class)]
#[Covers(RegressionFailed::class)]
final class PropertyResultSerializationTest
{
    public function everyOutcomeSurvivesSerializationWithArglessTraces(): void
    {
        $this->withExceptionIgnoreArgs('1', function (): void {
            foreach ($this->allOutcomes() as $result) {
                $restored = unserialize(serialize($result));

                Assert::instanceOf($restored, $result::class);
                Assert::same($restored->failure()?->getMessage(), $result->failure()?->getMessage());

                if ($result instanceof Falsified) {
                    \assert($restored instanceof Falsified);
                    Assert::same($restored->counterExample()->toArray(), $result->counterExample()->toArray());
                }

                if ($result instanceof Passed) {
                    \assert($restored instanceof Passed);
                    Assert::same($restored->statistics->checks, $result->statistics->checks);
                    Assert::same($restored->statistics->classifications, $result->statistics->classifications);
                }
            }
        });
    }

    public function passedSurvivesSerializationRegardlessOfTraceArguments(): void
    {
        $this->withExceptionIgnoreArgs('0', static function (): void {
            $result = (new PropertyRunner())->run(
                self::definition(),
                new CallableTrialExecutor(static function (int $value): void {
                    Classify::when($value >= 0, 'non-negative');
                }),
            );

            Assert::instanceOf($result, Passed::class);

            $restored = unserialize(serialize($result));

            Assert::instanceOf($restored, Passed::class);
            Assert::same($restored->statistics->classifications, $result->statistics->classifications);
        });
    }

    public function traceArgumentCaptureMakesFailingResultsEnvironmentDependent(): void
    {
        $this->withExceptionIgnoreArgs('0', static function (): void {
            $result = (new PropertyRunner())->run(
                self::definition(),
                new CallableTrialExecutor(static function (int $value): void {
                    if ($value >= 0) {
                        throw new \RuntimeException('always fails');
                    }
                }),
            );

            Assert::instanceOf($result, Falsified::class);

            // The trace's frame arguments hold the executor's closure; native
            // serialize() refuses closures. This is the environmental boundary
            // the docblock contract draws — not an engine defect to fix.
            $thrown = null;

            try {
                serialize($result);
            } catch (\Throwable $exception) {
                $thrown = $exception;
            }

            Assert::instanceOf($thrown, \Exception::class);
            // The failure mode is what matters (a closure in the graph refuses
            // native serialization); PHP owns the exact message wording.
            Assert::string($thrown->getMessage())->contains('Closure');
        });
    }

    /**
     * One realistic instance of every outcome in the closed hierarchy,
     * produced by the runner wherever the outcome is reachable from a plain
     * definition.
     *
     * @return array<string, PropertyResult>
     */
    private function allOutcomes(): array
    {
        $runner = new PropertyRunner();

        $passed = $runner->run(
            self::definition(),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::when($value >= 0, 'non-negative');
            }),
        );

        $falsified = $runner->run(
            self::definition(),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value > 100) {
                    throw new \RuntimeException(sprintf('%d exceeds 100', $value));
                }
            }),
        );

        $gaveUp = $runner->run(
            self::definition(maxDiscards: 3),
            new CallableTrialExecutor(static fn() => Assume::that(condition: false)),
        );

        $coverageFailed = $runner->run(
            self::definition(),
            new CallableTrialExecutor(static function (int $value): void {
                Classify::cover($value < 0, 'negative', 99.0);
            }),
        );

        $generationFailed = $runner->run(
            new PropertyDefinition(
                id: 'serialization::neverGenerates',
                name: 'neverGenerates',
                generators: ['value' => Gen::filter(Gen::int(), static fn(): bool => false)],
                parameterNames: ['value'],
                config: new PropertyConfig(runs: 5, seed: 42),
            ),
            new CallableTrialExecutor(static function (): void {}),
        );

        $exampleFailed = $runner->run(
            new PropertyDefinition(
                id: 'serialization::failingExample',
                name: 'failingExample',
                generators: ['value' => Gen::intBetween(0, 10)],
                parameterNames: ['value'],
                config: new PropertyConfig(runs: 5, seed: 42),
                examples: [[999]],
            ),
            new CallableTrialExecutor(static function (int $value): void {
                if ($value > 10) {
                    throw new \RuntimeException('example escaped the domain');
                }
            }),
        );

        // Every clock reading advances 2 ms, so the first passing run overruns
        // the 1 ms deadline and the 1 ms phase budget is exceeded on the loop's
        // second look at the clock.
        $deadlineExceeded = (new PropertyRunner(new FakeClock(stepNs: 2_000_000)))->run(
            self::definition(timeoutMs: 1),
            new CallableTrialExecutor(static function (): void {}),
        );

        $budgetExceeded = (new PropertyRunner(new FakeClock(stepNs: 2_000_000)))->run(
            self::definition(budgetMs: 1),
            new CallableTrialExecutor(static function (): void {}),
        );

        $regressionFailed = new RegressionFailed(new RegressionViolationException(
            arguments: ['value' => 101],
            seed: 42,
            failure: new \RuntimeException('101 exceeds 100'),
        ));

        Assert::instanceOf($passed, Passed::class);
        Assert::instanceOf($falsified, Falsified::class);
        Assert::instanceOf($gaveUp, GaveUp::class);
        Assert::instanceOf($coverageFailed, CoverageFailed::class);
        Assert::instanceOf($generationFailed, GenerationFailed::class);
        Assert::instanceOf($exampleFailed, ExampleFailed::class);
        Assert::instanceOf($deadlineExceeded, DeadlineExceeded::class);
        Assert::instanceOf($budgetExceeded, TimeBudgetExceeded::class);

        return [
            'passed' => $passed,
            'falsified' => $falsified,
            'gaveUp' => $gaveUp,
            'coverageFailed' => $coverageFailed,
            'generationFailed' => $generationFailed,
            'exampleFailed' => $exampleFailed,
            'deadlineExceeded' => $deadlineExceeded,
            'timeBudgetExceeded' => $budgetExceeded,
            'regressionFailed' => $regressionFailed,
        ];
    }

    private static function definition(
        ?int $maxDiscards = null,
        ?int $timeoutMs = null,
        ?int $budgetMs = null,
    ): PropertyDefinition {
        return new PropertyDefinition(
            id: 'serialization::property',
            name: 'property',
            generators: ['value' => Gen::intBetween(0, 10_000)],
            parameterNames: ['value'],
            config: new PropertyConfig(
                runs: 10,
                seed: 42,
                maxDiscards: $maxDiscards,
                timeoutMs: $timeoutMs,
                budgetMs: $budgetMs,
            ),
        );
    }

    private function withExceptionIgnoreArgs(string $value, \Closure $body): void
    {
        $previous = ini_set('zend.exception_ignore_args', $value);

        try {
            $body();
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }
}
