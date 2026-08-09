<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\TimeBudgetExceededException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TimeBudgetExceededException::class)]
final class TimeBudgetExceededExceptionTest
{
    public function storesAllFieldsVerbatim(): void
    {
        $exception = new TimeBudgetExceededException(
            propertyName: 'holds',
            budgetMs: 500,
            elapsedMs: 512.3,
            successfulRuns: 42,
            requiredRuns: 100,
        );

        Assert::same($exception->propertyName, 'holds');
        Assert::same($exception->budgetMs, 500);
        Assert::same($exception->elapsedMs, 512.3);
        Assert::same($exception->successfulRuns, 42);
        Assert::same($exception->requiredRuns, 100);
    }

    public function rendersTheBudgetElapsedTimeAndRunCounts(): void
    {
        $exception = new TimeBudgetExceededException(
            propertyName: 'holds',
            budgetMs: 500,
            elapsedMs: 512.34,
            successfulRuns: 42,
            requiredRuns: 100,
        );

        Assert::same(
            $exception->getMessage(),
            'Property "holds" exceeded its 500 ms time budget after 512.3 ms with 42/100 successful run(s). '
            . 'Raise budgetMs, lower runs, or speed up the property body.',
        );
    }
}
