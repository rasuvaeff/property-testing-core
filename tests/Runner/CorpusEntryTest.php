<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CorpusEntry::class)]
final class CorpusEntryTest
{
    public function valuesEntryCarriesItsArgumentsAndSeed(): void
    {
        $entry = CorpusEntry::values(['x' => 51], 4242);

        Assert::true($entry->isValues());
        Assert::same($entry->arguments, ['x' => 51]);
        Assert::same($entry->seed, 4242);
    }

    public function seedEntryHasNoArguments(): void
    {
        $entry = CorpusEntry::seed(-7);

        Assert::false($entry->isValues());
        Assert::null($entry->arguments);
        Assert::same($entry->seed, -7);
        Assert::null($entry->runsBeforeFailure);
    }

    public function seedEntryCarriesTheRecordedRunsBeforeFailure(): void
    {
        Assert::same(CorpusEntry::seed(11, runsBeforeFailure: 3)->runsBeforeFailure, 3);
    }

    public function valuesEntryNeverCarriesRunsBeforeFailure(): void
    {
        Assert::null(CorpusEntry::values(['x' => 1], 2)->runsBeforeFailure);
    }

    /**
     * An empty argument list still makes a values entry — a zero-arity property
     * has a reproducible input, namely no arguments at all.
     */
    public function anEmptyArgumentListIsStillAValuesEntry(): void
    {
        Assert::true(CorpusEntry::values([], 1)->isValues());
    }
}
