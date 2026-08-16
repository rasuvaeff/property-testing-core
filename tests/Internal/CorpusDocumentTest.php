<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Internal\CorpusDocument;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(CorpusDocument::class)]
final class CorpusDocumentTest
{
    private const int EPOCH = 1;

    public function seedEntryOmitsRunsBeforeFailureWhenUnknown(): void
    {
        Assert::same(
            CorpusDocument::seedEntry(99, self::EPOCH),
            ['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH],
        );
    }

    public function seedEntryStoresRunsBeforeFailure(): void
    {
        Assert::same(
            CorpusDocument::seedEntry(99, self::EPOCH, runsBeforeFailure: 3),
            ['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH, 'runsBeforeFailure' => 3],
        );
    }

    /**
     * A counterexample with `draw#N` pseudo-arguments is unrepresentable as
     * values, and its seed entry must remember how deep the failure sat.
     */
    public function encodeEntryFallsBackToASeedEntryCarryingRunsBeforeFailure(): void
    {
        $entry = CorpusDocument::encodeEntry(
            new CounterExample(
                seed: 7,
                runsBeforeFailure: 4,
                originalArguments: ['draw#1' => 5],
                shrunkArguments: ['draw#1' => 4],
            ),
            [],
            self::EPOCH,
        );

        Assert::same($entry, ['kind' => 'seed', 'seed' => 7, 'epoch' => self::EPOCH, 'runsBeforeFailure' => 4]);
    }

    public function hydrateReadsRunsBeforeFailure(): void
    {
        $entry = CorpusDocument::hydrate(
            ['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH, 'runsBeforeFailure' => 3],
            [],
            self::EPOCH,
        );

        Assert::instanceOf($entry, CorpusEntry::class);
        Assert::same($entry->seed, 99);
        Assert::same($entry->runsBeforeFailure, 3);
    }

    /**
     * A corrupt or foreign runsBeforeFailure must not poison the entry: the
     * seed still replays, only without the extension.
     */
    #[DataProvider('unusableRunsBeforeFailureProvider')]
    public function hydrateDropsAnUnusableRunsBeforeFailure(mixed $runsBeforeFailure): void
    {
        $raw = ['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH];

        if ($runsBeforeFailure !== null) {
            $raw['runsBeforeFailure'] = $runsBeforeFailure;
        }

        $entry = CorpusDocument::hydrate($raw, [], self::EPOCH);

        Assert::instanceOf($entry, CorpusEntry::class);
        Assert::same($entry->seed, 99);
        Assert::null($entry->runsBeforeFailure);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableRunsBeforeFailureProvider(): iterable
    {
        yield 'absent (pre-field document)' => [null];
        yield 'negative' => [-1];
        yield 'not an int' => ['3'];
        yield 'PHP_INT_MAX (would overflow the +1 extension)' => [PHP_INT_MAX];
    }

    public function hydrateStillFencesSeedEntriesByEpoch(): void
    {
        Assert::null(CorpusDocument::hydrate(
            ['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH + 1, 'runsBeforeFailure' => 3],
            [],
            self::EPOCH,
        ));
    }
}
