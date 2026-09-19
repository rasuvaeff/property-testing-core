<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\CoverageViolationException;
use Rasuvaeff\PropertyTesting\PropertyTestingException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CoverageViolationException::class)]
final class CoverageViolationExceptionTest
{
    public function isARuntimeExceptionCarryingTheRunnersMessage(): void
    {
        $exception = new CoverageViolationException('Coverage requirement not met: "even" 3.0% < 5.0%');

        Assert::same($exception->getMessage(), 'Coverage requirement not met: "even" 3.0% < 5.0%');
        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::instanceOf($exception, PropertyTestingException::class);
    }
}
