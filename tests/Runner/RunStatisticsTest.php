<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\CoverageViolationException;
use Rasuvaeff\PropertyTesting\GaveUpException;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\Runner\CoverageFailed;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
use Rasuvaeff\PropertyTesting\Runner\TimeBudgetExceeded;
use Rasuvaeff\PropertyTesting\TimeBudgetExceededException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RunStatistics::class)]
final class RunStatisticsTest
{
    public function theOutcomesThatCarryStatisticsHandThemOver(): void
    {
        $statistics = new RunStatistics(attempts: 3, discards: 1, checks: 2, classifications: []);

        Assert::same(RunStatistics::of(new Passed($statistics)), $statistics);
        Assert::same(RunStatistics::of(new GaveUp(new GaveUpException('p', 3, 2, 1, 3, 0), $statistics)), $statistics);
        Assert::same(RunStatistics::of(new CoverageFailed(new CoverageViolationException('coverage not met'), $statistics)), $statistics);
        Assert::same(RunStatistics::of(new TimeBudgetExceeded(new TimeBudgetExceededException('p', 1, 2.0, 2, 3), $statistics)), $statistics);
    }

    public function anyOtherOutcomeCarriesNone(): void
    {
        Assert::null(RunStatistics::of(new Falsified(new PropertyViolationException(new CounterExample(1, 0, [], [])))));
    }

    public function discardedExamplesDefaultToNone(): void
    {
        Assert::same((new RunStatistics(attempts: 0, discards: 0, checks: 0, classifications: []))->discardedExamples, 0);
    }
}
