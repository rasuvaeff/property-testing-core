<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\PathViolationException;
use Rasuvaeff\PropertyTesting\PropertyTestingException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PathViolationException::class)]
final class PathViolationExceptionTest
{
    public function messageNamesTheStepAndTheReasonAndTheGettersReturnTheParts(): void
    {
        $exception = new PathViolationException('value:1/draw#1:0', 2, 'draw#1:0', 'names no candidate');

        Assert::same(
            $exception->getMessage(),
            'Shrink path "value:1/draw#1:0" no longer applies: step 2 ("draw#1:0") names no candidate. Re-run without a path to search for the counterexample again',
        );
        Assert::same($exception->getPath(), 'value:1/draw#1:0');
        Assert::same($exception->getStep(), 2);
        Assert::same($exception->getSegment(), 'draw#1:0');
        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::instanceOf($exception, PropertyTestingException::class);
    }
}
