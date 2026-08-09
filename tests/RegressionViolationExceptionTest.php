<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\RegressionViolationException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RegressionViolationException::class)]
final class RegressionViolationExceptionTest
{
    public function messageNamesTheRecordedInputAndItsOriginalSeed(): void
    {
        $exception = new RegressionViolationException(['x' => 51, 'flag' => true], 4242);

        Assert::string($exception->getMessage())
            ->contains('Recorded regression failed')
            ->contains('seed 4242')
            ->contains('x=51')
            ->contains('flag=true');
    }

    public function messageAppendsTheReplayFailure(): void
    {
        $exception = new RegressionViolationException(['x' => 1], 7, new \RuntimeException('boom'));

        // Appended, not substituted: the input stays in the message.
        Assert::string($exception->getMessage())
            ->contains('Recorded regression failed')
            ->contains('seed 7')
            ->contains('x=1')
            ->contains('Failure:  boom');
    }

    public function carriesTheFailureAsItsPrevious(): void
    {
        $failure = new \RuntimeException('boom');

        Assert::same((new RegressionViolationException([], 1, $failure))->getPrevious(), $failure);
    }

    public function exposesTheRecordedInputAndSeed(): void
    {
        $exception = new RegressionViolationException(['x' => 51], 4242);

        Assert::same($exception->getArguments(), ['x' => 51]);
        Assert::same($exception->getSeed(), 4242);
    }

    public function rendersNestedValuesLikeEveryOtherCounterexample(): void
    {
        $exception = new RegressionViolationException(['a' => ['k' => 1]], 1);

        Assert::string($exception->getMessage())->contains('a=["k" => 1]');
    }
}
