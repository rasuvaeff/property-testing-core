<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\ExampleViolationException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ExampleViolationException::class)]
final class ExampleViolationExceptionTest
{
    public function exposesIndexAndArguments(): void
    {
        $exception = new ExampleViolationException(2, [1, 'x']);

        Assert::same($exception->getIndex(), 2);
        Assert::same($exception->getArguments(), [1, 'x']);
    }

    public function rendersEveryArgumentStyle(): void
    {
        $stringable = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'STR';
            }
        };

        $exception = new ExampleViolationException(
            3,
            ['s', true, false, null, [1, 2], 7, 3.5, $stringable, new \stdClass()],
        );
        $message = $exception->getMessage();

        Assert::string($message)->contains('Explicit example #3');
        Assert::string($message)->contains('"s"');
        Assert::string($message)->contains('true');
        Assert::string($message)->contains('false');
        Assert::string($message)->contains('null');
        Assert::string($message)->contains('[1, 2]');
        Assert::string($message)->contains('7');
        Assert::string($message)->contains('3.5');
        Assert::string($message)->contains('STR');
        Assert::string($message)->contains('stdClass');
    }

    public function boolArgumentsRenderTrueAndFalseDistinctly(): void
    {
        $trueMessage = (new ExampleViolationException(0, [true]))->getMessage();
        $falseMessage = (new ExampleViolationException(0, [false]))->getMessage();

        Assert::string($trueMessage)->contains('true');
        Assert::same(str_contains($trueMessage, 'false'), expected: false);
        Assert::string($falseMessage)->contains('false');
    }

    public function chainsTheUnderlyingFailure(): void
    {
        $cause = new \RuntimeException('boom');
        $exception = new ExampleViolationException(0, [1], $cause);

        Assert::same($exception->getPrevious(), $cause);
        // The failure line is APPENDED to the example header, not replacing it.
        Assert::same($exception->getMessage(), "Explicit example #0 failed: [1]\n  Failure:  boom");
    }

    public function omitsTheFailureLineWhenNoCause(): void
    {
        $exception = new ExampleViolationException(0, [1]);

        Assert::same(str_contains($exception->getMessage(), 'Failure:'), expected: false);
    }
}
