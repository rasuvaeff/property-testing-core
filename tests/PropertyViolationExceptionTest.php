<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PropertyViolationException::class)]
final class PropertyViolationExceptionTest
{
    public function rendersSeedOriginalAndShrunkArguments(): void
    {
        $exception = new PropertyViolationException(new CounterExample(
            seed: 7382910,
            runsBeforeFailure: 246,
            originalArguments: ['x' => 23],
            shrunkArguments: ['x' => 1],
            shrinkSteps: 12,
        ));

        Assert::string($exception->getMessage())->contains('seed=7382910');
        Assert::string($exception->getMessage())->contains('246 successful run(s)');
        Assert::string($exception->getMessage())->contains('Original: x=23');
        Assert::string($exception->getMessage())->contains('Shrunk:   x=1 (12 shrink step(s))');
    }

    public function rendersTheTrialCountNextToTheAcceptedSteps(): void
    {
        $exception = new PropertyViolationException(new CounterExample(
            seed: 1,
            runsBeforeFailure: 0,
            originalArguments: ['x' => 23],
            shrunkArguments: ['x' => 1],
            shrinkSteps: 12,
            shrinkTrials: 47,
        ));

        Assert::string($exception->getMessage())->contains('(12 shrink step(s), 47 trial(s))');
    }

    public function omitsTheTrialCountWhenNoCandidateWasTried(): void
    {
        $exception = new PropertyViolationException(new CounterExample(
            seed: 1,
            runsBeforeFailure: 0,
            originalArguments: ['x' => 23],
            shrunkArguments: ['x' => 23],
        ));

        Assert::false(str_contains($exception->getMessage(), 'trial(s)'));
    }

    public function rendersAChangedDiffForTheArgumentsThatShrank(): void
    {
        $exception = new PropertyViolationException(new CounterExample(
            seed: 1,
            runsBeforeFailure: 0,
            originalArguments: ['x' => 23, 'y' => 5, 'draw#1' => 7],
            shrunkArguments: ['x' => 1, 'y' => 5],
            shrinkSteps: 3,
        ));

        $message = $exception->getMessage();

        Assert::string($message)->contains('Changed:  x=23 -> 1');
        // A draw dropped by tape truncation appears as absent on the shrunk side.
        Assert::string($message)->contains('draw#1=7 -> (absent)');
        // Unchanged arguments stay out of the diff.
        Assert::false(str_contains($message, 'y=5 ->'));
    }

    public function omitsTheChangedLineWhenNothingShrank(): void
    {
        $exception = new PropertyViolationException(new CounterExample(
            seed: 1,
            runsBeforeFailure: 0,
            originalArguments: ['x' => 23],
            shrunkArguments: ['x' => 23],
        ));

        Assert::false(str_contains($exception->getMessage(), 'Changed:'));
    }

    public function rendersTheUnderlyingFailureMessage(): void
    {
        $exception = new PropertyViolationException(new CounterExample(
            seed: 1,
            runsBeforeFailure: 0,
            originalArguments: ['x' => 51],
            shrunkArguments: ['x' => 51],
            failure: new \RuntimeException('cap exceeded'),
        ));

        // The failure line is appended to the counterexample rendering, never
        // replacing it.
        Assert::string($exception->getMessage())->contains('seed=1');
        Assert::string($exception->getMessage())->contains('Failure:  cap exceeded');
    }

    public function omitsTheFailureLineWhenNoUnderlyingFailureIsCaptured(): void
    {
        $exception = new PropertyViolationException(new CounterExample(
            seed: 1,
            runsBeforeFailure: 0,
            originalArguments: ['x' => 51],
            shrunkArguments: ['x' => 51],
        ));

        Assert::false(str_contains($exception->getMessage(), 'Failure:'));
    }

    public function chainsTheUnderlyingFailureAsPrevious(): void
    {
        $failure = new \RuntimeException('boom');
        $exception = new PropertyViolationException(new CounterExample(
            seed: 1,
            runsBeforeFailure: 0,
            originalArguments: [],
            shrunkArguments: [],
            failure: $failure,
        ));

        Assert::same($exception->getPrevious(), $failure);
    }

    public function rendersEachArgumentTypeReadably(): void
    {
        $exception = new PropertyViolationException(new CounterExample(
            seed: 1,
            runsBeforeFailure: 0,
            originalArguments: [
                'i' => 7,
                's' => 'hi',
                'list' => [1, 2, 3],
                'yes' => true,
                'no' => false,
                'nothing' => null,
                'obj' => new \stdClass(),
                'stringy' => new class implements \Stringable {
                    #[\Override]
                    public function __toString(): string
                    {
                        return 'CMD';
                    }
                },
            ],
            shrunkArguments: ['i' => 0],
        ));

        $message = $exception->getMessage();

        Assert::string($message)->contains('i=7');
        Assert::string($message)->contains('s="hi"');
        Assert::string($message)->contains('list=[1, 2, 3]');
        Assert::string($message)->contains('yes=true');
        Assert::string($message)->contains('no=false');
        Assert::string($message)->contains('nothing=null');
        Assert::string($message)->contains('obj=stdClass');
        Assert::string($message)->contains('stringy=CMD');
    }

    public function exposesTheCounterExample(): void
    {
        $counterExample = new CounterExample(
            seed: 42,
            runsBeforeFailure: 3,
            originalArguments: ['x' => 9],
            shrunkArguments: ['x' => 1],
        );
        $exception = new PropertyViolationException($counterExample);

        Assert::same($exception->getCounterExample(), $counterExample);
    }
}
