<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\GenerationErrorException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(GenerationErrorException::class)]
final class GenerationErrorExceptionTest
{
    public function exposesThePropertySeedAttemptAndTheGeneratorsException(): void
    {
        $previous = new \LogicException('map bug', 7);
        $exception = new GenerationErrorException('roundTrip', 123, 4, $previous);

        Assert::same($exception->propertyName, 'roundTrip');
        Assert::same($exception->seed, 123);
        Assert::same($exception->attempt, 4);
        Assert::same($exception->getPrevious(), $previous);
        Assert::same($exception->getCode(), 0);
    }

    public function messageNamesWhatReproducesTheErrorAndWhatWasThrown(): void
    {
        $exception = new GenerationErrorException('roundTrip', 123, 4, new \DomainException('negative input'));

        Assert::same(
            $exception->getMessage(),
            "Property \"roundTrip\": a generator threw while drawing the input of attempt 4; seed=123\n  Error:    DomainException: negative input",
        );
    }
}
