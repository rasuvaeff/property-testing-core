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
        Assert::same($exception->skippedRuns, 0);
        Assert::false($exception->exhaustedBySkips);
        Assert::same(
            $exception->getMessage(),
            'Property "holds" gave up after 33 attempt(s): 12/100 successful run(s), 21 discarded (maximum 20). '
            . 'Narrow or construct the generators so inputs are valid by construction.',
        );
    }

    /**
     * The skip budget has its own message: advising narrower generators to a
     * machine that was missing a dependency is advice that cannot be acted on.
     */
    public function skipExhaustionBlamesTheEnvironmentInsteadOfTheGenerators(): void
    {
        $exception = new GaveUpException('holds', 100, 12, 0, 33, 20, skippedRuns: 21, exhaustedBySkips: true, maxSkips: 10);

        Assert::same($exception->skippedRuns, 21);
        Assert::true($exception->exhaustedBySkips);
        Assert::same(
            $exception->getMessage(),
            'Property "holds" gave up after 33 attempt(s): 12/100 successful run(s), 21 skipped (maximum 10). '
            . 'The environment refused those runs, so the generators are not the cause: '
            . 'a missing dependency or a lifecycle hook skipped this property more often than it checked it.',
        );
    }

    /**
     * A caller that never told the two budgets apart has no separate cap to
     * report, and a message reading "maximum 0" would name a limit nothing was
     * measured against.
     */
    public function anUnspecifiedSkipCapFallsBackToTheDiscardCap(): void
    {
        $exception = new GaveUpException('holds', 100, 12, 0, 33, 20, skippedRuns: 21, exhaustedBySkips: true);

        Assert::null($exception->maxSkips);
        Assert::string($exception->getMessage())->contains('21 skipped (maximum 20)');
    }
}
