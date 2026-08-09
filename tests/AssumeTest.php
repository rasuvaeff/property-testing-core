<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\AssumptionSkipped;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Assume::class)]
#[Covers(AssumptionSkipped::class)]
final class AssumeTest
{
    public function trueConditionDoesNotThrow(): void
    {
        Assume::that(condition: true);

        Assert::true(actual: true);
    }

    #[ExpectException(AssumptionSkipped::class)]
    public function falseConditionThrowsAssumptionSkipped(): void
    {
        Assume::that(condition: false);
    }

    public function assumptionSkippedCarriesTheDiscardMessage(): void
    {
        $exception = new AssumptionSkipped();

        Assert::same($exception->getMessage(), 'Assumption not satisfied; property run discarded');
    }
}
