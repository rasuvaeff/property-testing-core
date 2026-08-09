<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(GenerationExhausted::class)]
final class GenerationExhaustedTest
{
    public function exposesTheArbitraryAndAttemptCount(): void
    {
        $exception = new GenerationExhausted('Gen::filter()', 100, 'the predicate rejected every value');

        Assert::same($exception->arbitrary, 'Gen::filter()');
        Assert::same($exception->attempts, 100);
    }

    public function messageCombinesLabelAttemptsAndReason(): void
    {
        $exception = new GenerationExhausted('Gen::dictOf()', 50, 'the key space is too small');
        $message = $exception->getMessage();

        Assert::string($message)->contains('Gen::dictOf()');
        Assert::string($message)->contains('50 attempt(s)');
        Assert::string($message)->contains('the key space is too small');
    }
}
