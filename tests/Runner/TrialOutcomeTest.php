<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\TrialOutcome;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The four outcomes an adapter can report, and the predicates the engine reads
 * them through. `skipped()` is a discard everywhere but one — the corpus replay
 * keeps an entry it could not judge — so `isDiscarded()` must stay true for it
 * while `isSkipped()` separates the two.
 */
#[Test]
#[Covers(TrialOutcome::class)]
final class TrialOutcomeTest
{
    public function passedIsOnlyPassed(): void
    {
        $outcome = TrialOutcome::passed();

        Assert::true($outcome->isPassed());
        Assert::false($outcome->isFailed());
        Assert::false($outcome->isDiscarded());
        Assert::false($outcome->isSkipped());
        Assert::null($outcome->failure);
    }

    public function failedCarriesTheThrowable(): void
    {
        $failure = new \RuntimeException('boom');
        $outcome = TrialOutcome::failed($failure);

        Assert::false($outcome->isPassed());
        Assert::true($outcome->isFailed());
        Assert::false($outcome->isDiscarded());
        Assert::false($outcome->isSkipped());
        Assert::same($outcome->failure, $failure);
    }

    public function failedWithoutAThrowableIsStillAFailure(): void
    {
        $outcome = TrialOutcome::failed();

        Assert::true($outcome->isFailed());
        Assert::false($outcome->isSkipped());
        Assert::null($outcome->failure);
    }

    public function discardedIsNotASkip(): void
    {
        $outcome = TrialOutcome::discarded();

        Assert::false($outcome->isPassed());
        Assert::false($outcome->isFailed());
        Assert::true($outcome->isDiscarded());
        // The input left the domain — a statement about the input, which is
        // what lets the corpus prune a regression that discards on replay.
        Assert::false($outcome->isSkipped());
        Assert::null($outcome->failure);
    }

    public function skippedIsADiscardThatSaysNothingAboutTheInput(): void
    {
        $outcome = TrialOutcome::skipped();

        Assert::false($outcome->isPassed());
        Assert::false($outcome->isFailed());
        // Counts as a discard everywhere the engine counts discards...
        Assert::true($outcome->isDiscarded());
        // ...and is separable exactly where that matters.
        Assert::true($outcome->isSkipped());
        Assert::null($outcome->failure);
    }
}
