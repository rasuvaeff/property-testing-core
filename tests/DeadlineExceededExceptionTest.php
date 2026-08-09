<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\DeadlineExceededException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DeadlineExceededException::class)]
final class DeadlineExceededExceptionTest
{
    public function storesAllFieldsVerbatim(): void
    {
        $exception = new DeadlineExceededException(
            propertyName: 'holds',
            arguments: ['x' => 51, 'draw#1' => 7],
            elapsedMs: 123.4,
            timeoutMs: 100,
        );

        Assert::same($exception->propertyName, 'holds');
        Assert::same($exception->arguments, ['x' => 51, 'draw#1' => 7]);
        Assert::same($exception->elapsedMs, 123.4);
        Assert::same($exception->timeoutMs, 100);
    }

    public function rendersTheDeadlineElapsedTimeAndArguments(): void
    {
        $exception = new DeadlineExceededException(
            propertyName: 'holds',
            arguments: ['x' => 51, 's' => 'hi'],
            elapsedMs: 123.46,
            timeoutMs: 100,
        );

        Assert::same(
            $exception->getMessage(),
            'Property "holds" run exceeded its 100 ms deadline (took 123.5 ms) for x=51, s="hi". '
            . 'The input is pathological for the code under test, or the deadline is too tight.',
        );
    }

    public function rendersAPlaceholderWhenThereAreNoArguments(): void
    {
        $exception = new DeadlineExceededException(
            propertyName: 'holds',
            arguments: [],
            elapsedMs: 5.0,
            timeoutMs: 1,
        );

        Assert::string($exception->getMessage())->contains('(no arguments)');
    }
}
