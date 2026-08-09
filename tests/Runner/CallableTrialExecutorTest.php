<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\TrialOutcome;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CallableTrialExecutor::class)]
#[Covers(TrialOutcome::class)]
final class CallableTrialExecutorTest
{
    public function normalReturnPasses(): void
    {
        $executor = new CallableTrialExecutor(static function (): void {});

        $outcome = $executor->execute([]);

        Assert::true($outcome->isPassed());
        Assert::false($outcome->isFailed());
        Assert::false($outcome->isDiscarded());
        Assert::null($outcome->failure);
    }

    public function assumeDiscards(): void
    {
        $executor = new CallableTrialExecutor(static fn() => Assume::that(condition: false));

        $outcome = $executor->execute([]);

        Assert::true($outcome->isDiscarded());
        Assert::false($outcome->isPassed());
        Assert::false($outcome->isFailed());
        Assert::null($outcome->failure);
    }

    public function throwableFailsWithTheVeryInstance(): void
    {
        $failure = new \RuntimeException('boom');
        $executor = new CallableTrialExecutor(static fn() => throw $failure);

        $outcome = $executor->execute([]);

        Assert::true($outcome->isFailed());
        Assert::false($outcome->isPassed());
        Assert::false($outcome->isDiscarded());
        Assert::same($outcome->failure, $failure);
    }

    public function argumentsArePassedPositionallyInOrder(): void
    {
        $received = null;
        $executor = new CallableTrialExecutor(static function (int $first, string $second) use (&$received): void {
            $received = [$first, $second];
        });

        $executor->execute(['first' => 7, 'second' => 'seven']);

        Assert::same($received, [7, 'seven']);
    }

    /**
     * Argument keys (parameter names, `draw#N` pseudo-arguments) must never
     * leak into the call as named arguments — the body receives values purely
     * by position, whatever the keys are.
     */
    public function argumentKeysAreNotPassedAsNamedArguments(): void
    {
        $received = null;
        $executor = new CallableTrialExecutor(static function (int $a, string $b) use (&$received): void {
            $received = [$a, $b];
        });

        $outcome = $executor->execute(['x' => 7, 'draw#1' => 'seven']);

        Assert::true($outcome->isPassed());
        Assert::same($received, [7, 'seven']);
    }
}
