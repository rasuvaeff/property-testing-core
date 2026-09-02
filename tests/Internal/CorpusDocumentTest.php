<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Internal\CorpusDocument;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
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
            ['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH, 'edgeCases' => 'mixin'],
        );
    }

    public function seedEntryStoresRunsBeforeFailure(): void
    {
        Assert::same(
            CorpusDocument::seedEntry(99, self::EPOCH, runsBeforeFailure: 3),
            ['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH, 'runsBeforeFailure' => 3, 'edgeCases' => 'mixin'],
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

        Assert::same($entry, ['kind' => 'seed', 'seed' => 7, 'epoch' => self::EPOCH, 'runsBeforeFailure' => 4, 'edgeCases' => 'mixin']);
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

    public function seedEntryStoresTheEdgeCaseMode(): void
    {
        Assert::same(
            CorpusDocument::seedEntry(99, self::EPOCH, edgeCases: EdgeCases::None),
            ['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH, 'edgeCases' => 'none'],
        );
        Assert::same(CorpusDocument::seedEntry(99, self::EPOCH)['edgeCases'], 'mixin');
    }

    public function hydrateReadsTheEdgeCaseMode(): void
    {
        $entry = CorpusDocument::hydrate(['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH, 'edgeCases' => 'none'], [], self::EPOCH);

        Assert::instanceOf($entry, CorpusEntry::class);
        Assert::same($entry->edgeCases, EdgeCases::None);
    }

    public function hydrateReadsAPreFieldSeedEntryAsMixin(): void
    {
        $entry = CorpusDocument::hydrate(['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH], [], self::EPOCH);

        Assert::instanceOf($entry, CorpusEntry::class);
        Assert::same($entry->edgeCases, EdgeCases::Mixin);
    }

    #[DataProvider('unknownEdgeCaseModes')]
    public function hydrateDropsASeedEntryWithAModeItCannotReplay(mixed $mode): void
    {
        // A mode this reader does not know cannot reproduce the recorded values.
        Assert::null(CorpusDocument::hydrate(['kind' => 'seed', 'seed' => 99, 'epoch' => self::EPOCH, 'edgeCases' => $mode], [], self::EPOCH));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unknownEdgeCaseModes(): iterable
    {
        yield 'unknown name' => ['targeted'];
        yield 'wrong case' => ['Mixin'];
        yield 'not a string' => [1];
        yield 'null' => [null];
    }

    public function keyOfIgnoresTheOrderOfTheArguments(): void
    {
        // hydrate() hands a reordered signature back in the current order, so
        // a pruned entry re-encodes with its arguments in a different order
        // than the stored bytes — same input, same key.
        $stored = ['kind' => 'values', 'seed' => 1, 'epoch' => self::EPOCH, 'args' => ['a' => 1, 'b' => 2]];
        $reencoded = ['kind' => 'values', 'seed' => 1, 'epoch' => self::EPOCH, 'args' => ['b' => 2, 'a' => 1]];

        Assert::same(CorpusDocument::keyOf($stored), CorpusDocument::keyOf($reencoded));
        Assert::true(CorpusDocument::keyOf($stored) !== CorpusDocument::keyOf(['kind' => 'values', 'seed' => 1, 'epoch' => self::EPOCH, 'args' => ['a' => 2, 'b' => 1]]));
    }

    public function aDocumentOfAnotherFormatVersionIsForeignCorruptContentIsNot(): void
    {
        Assert::true(CorpusDocument::isForeignFormat('{"format": 99, "entries": []}', 1));
        Assert::true(CorpusDocument::isForeignFormat('{"format": "1", "entries": []}', 1));
        Assert::false(CorpusDocument::isForeignFormat('{"format": 1, "entries": []}', 1));
        Assert::false(CorpusDocument::isForeignFormat('{"entries": []}', 1));
        Assert::false(CorpusDocument::isForeignFormat('not json', 1));
        Assert::false(CorpusDocument::isForeignFormat('[1, 2]', 1));
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
