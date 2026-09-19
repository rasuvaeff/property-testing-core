<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Flaky detection: after the descent the minimised input is re-executed, and
 * a replay that does not fail marks the counterexample as nondeterminism
 * rather than a fact about the input.
 */
#[Test]
#[Covers(PropertyRunner::class)]
final class PropertyRunnerFlakyTest
{
    public function aDeterministicFailureReproducesOnEveryReplay(): void
    {
        $result = $this->run(static function (int $n): void {
            if ($n >= 10) {
                throw new \RuntimeException('too big');
            }
        });

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->replays, 2);
        Assert::null($example->passedOnReplay);
        Assert::false($example->isFlaky());
        Assert::string($result->failure()->getMessage())->notContains('Flaky:');
    }

    public function aFailureThatStopsReproducingIsReportedFlaky(): void
    {
        // Fails exactly as often as the search needs to reach its minimum,
        // then passes: the first replay is the first execution that passes.
        $failingExecutions = $this->executionsBeforeReplays();

        $calls = 0;
        $result = $this->run(static function (int $n) use (&$calls, $failingExecutions): void {
            if ($n >= 10 && ++$calls <= $failingExecutions) {
                throw new \RuntimeException('too big');
            }
        });

        Assert::instanceOf($result, Falsified::class);
        $example = $result->counterExample();
        Assert::same($example->shrunkArguments, ['n' => 10]);
        Assert::same($example->replays, 1);
        Assert::same($example->passedOnReplay, 1);
        Assert::true($example->isFlaky());
        Assert::string($result->failure()->getMessage())->contains('Flaky:    the minimised input passed on replay 1;');
    }

    public function theSecondReplayCanBeTheOneThatPasses(): void
    {
        $failingExecutions = $this->executionsBeforeReplays() + 1;

        $calls = 0;
        $result = $this->run(static function (int $n) use (&$calls, $failingExecutions): void {
            if ($n >= 10 && ++$calls <= $failingExecutions) {
                throw new \RuntimeException('too big');
            }
        });

        Assert::instanceOf($result, Falsified::class);
        Assert::same($result->counterExample()->replays, 2);
        Assert::same($result->counterExample()->passedOnReplay, 2);
    }

    public function aReplayThatDiscardsCountsAsNotReproducing(): void
    {
        $failingExecutions = $this->executionsBeforeReplays();

        $calls = 0;
        $result = $this->run(static function (int $n) use (&$calls, $failingExecutions): void {
            if ($n >= 10) {
                Assume::that(++$calls <= $failingExecutions);

                throw new \RuntimeException('too big');
            }
        });

        Assert::instanceOf($result, Falsified::class);
        Assert::same($result->counterExample()->passedOnReplay, 1);
    }

    public function zeroReplaysExecutesNothingAfterTheDescent(): void
    {
        $withReplays = 0;
        $this->run(static function (int $n) use (&$withReplays): void {
            ++$withReplays;

            if ($n >= 10) {
                throw new \RuntimeException('too big');
            }
        });

        $without = 0;
        $result = $this->run(static function (int $n) use (&$without): void {
            ++$without;

            if ($n >= 10) {
                throw new \RuntimeException('too big');
            }
        }, replays: 0);

        Assert::instanceOf($result, Falsified::class);
        Assert::same($result->counterExample()->replays, 0);
        Assert::null($result->counterExample()->passedOnReplay);
        Assert::same($withReplays, $without + 2);
    }

    public function replaysEmitNoEvents(): void
    {
        $withReplays = new CollectingListener();
        $this->run(static function (int $n): void {
            if ($n >= 10) {
                throw new \RuntimeException('too big');
            }
        }, listener: $withReplays);

        $without = new CollectingListener();
        $this->run(static function (int $n): void {
            if ($n >= 10) {
                throw new \RuntimeException('too big');
            }
        }, replays: 0, listener: $without);

        $shapes = static fn(CollectingListener $listener): array => array_map(
            static fn(PropertyEvent $event): string => $event::class,
            $listener->events,
        );

        Assert::same($shapes($withReplays), $shapes($without));
    }

    public function aReplayedPathIsCheckedForFlakinessToo(): void
    {
        $path = $this->run(static function (int $n): void {
            if ($n >= 10) {
                throw new \RuntimeException('too big');
            }
        });
        Assert::instanceOf($path, Falsified::class);

        $result = $this->run(static function (int $n): void {
            if ($n >= 10) {
                throw new \RuntimeException('too big');
            }
        }, path: $path->counterExample()->path);

        Assert::instanceOf($result, Falsified::class);
        Assert::same($result->counterExample()->replays, 2);
        Assert::null($result->counterExample()->passedOnReplay);
    }

    /**
     * How many times the deterministic body above fails before the replays
     * start: every execution the random phase and the descent make.
     */
    private function executionsBeforeReplays(): int
    {
        $failures = 0;
        $this->run(static function (int $n) use (&$failures): void {
            if ($n >= 10) {
                ++$failures;

                throw new \RuntimeException('too big');
            }
        }, replays: 0);

        return $failures;
    }

    private function run(\Closure $body, int $replays = 2, ?CollectingListener $listener = null, ?string $path = null): PropertyResult
    {
        return (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'flaky::property',
                name: 'property',
                generators: ['n' => Gen::intBetween(0, 1000)],
                parameterNames: ['n'],
                config: new PropertyConfig(runs: 50, seed: 42, flakyReplays: $replays, path: $path),
            ),
            new CallableTrialExecutor($body),
            $listener === null ? [] : [$listener],
        );
    }
}
