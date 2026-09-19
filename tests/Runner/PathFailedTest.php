<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\PathViolationException;
use Rasuvaeff\PropertyTesting\Runner\PathFailed;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PathFailed::class)]
final class PathFailedTest
{
    public function carriesTheViolationAsItsFailure(): void
    {
        $exception = new PathViolationException('x:1/y:0', 2, 'y:0', 'names no candidate');
        $result = new PathFailed($exception);

        Assert::instanceOf($result, PropertyResult::class);
        Assert::same($result->exception, $exception);
        Assert::same($result->failure(), $exception);
    }
}
