<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\GaveUpException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(GaveUpException::class)]
final class GaveUpExceptionTest
{
    public function exposesRunAndDiscardCounts(): void
    {
        $exception = new GaveUpException('holds', 100, 12, 21, 33, 20);

        Assert::same($exception->propertyName, 'holds');
        Assert::same($exception->requiredRuns, 100);
        Assert::same($exception->successfulRuns, 12);
        Assert::same($exception->discardedRuns, 21);
        Assert::same($exception->attempts, 33);
        Assert::same($exception->maxDiscards, 20);
        Assert::same(
            $exception->getMessage(),
            'Property "holds" gave up after 33 attempt(s): 12/100 successful run(s), 21 discarded (maximum 20). '
            . 'Narrow or construct the generators so inputs are valid by construction.',
        );
    }
}
