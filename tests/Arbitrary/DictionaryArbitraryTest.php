<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\BoolArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\DictionaryArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\StringArbitrary;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DictionaryArbitrary::class)]
final class DictionaryArbitraryTest
{
    public function generateStaysWithinSizeRange(): void
    {
        // Distinct string keys avoid collisions so the map size tracks the drawn size.
        $arbitrary = new DictionaryArbitrary(new StringArbitrary(5, 5), new IntArbitrary(), 2, 8);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $count = count($arbitrary->generate($random)->value);

            Assert::true($count >= 1 && $count <= 8);
        }
    }

    public function generateDrawsKeysAndValuesFromTheirArbitraries(): void
    {
        $arbitrary = new DictionaryArbitrary(new StringArbitrary(3, 3), new IntArbitrary(42, 42), 4, 4);
        $dictionary = $arbitrary->generate(new Random(1))->value;

        foreach ($dictionary as $key => $value) {
            Assert::true(is_string($key));
            Assert::same($value, 42);
        }
    }

    public function generateProducesExactlySizeEntriesWithUniqueKeys(): void
    {
        // Long random string keys make collisions vanishingly unlikely, so a
        // fixed min == max size yields exactly that many entries. (String keys
        // avoid IntArbitrary's boundary bias, which would inflate collisions.)
        $arbitrary = new DictionaryArbitrary(new StringArbitrary(20, 20), new IntArbitrary(), 5, 5);

        Assert::same(count($arbitrary->generate(new Random(1))->value), 5);
    }

    public function acceptsMaximumSizeOfOne(): void
    {
        // maxSize === 1 is valid (the boundary of the "at least 1" rule).
        $arbitrary = new DictionaryArbitrary(new StringArbitrary(20, 20), new IntArbitrary(7, 7), 1, 1);

        Assert::same(count($arbitrary->generate(new Random(1))->value), 1);
    }

    public function generatesEmptyMapWhenSizeIsZero(): void
    {
        $arbitrary = new DictionaryArbitrary(new StringArbitrary(1, 5), new IntArbitrary(), 0, 3);
        $random = new Random(1);
        $sawEmpty = false;

        for ($i = 0; $i < 200; ++$i) {
            if ($arbitrary->generate($random)->value === []) {
                $sawEmpty = true;

                break;
            }
        }

        Assert::true($sawEmpty);
    }

    public function shrinkTriesEmptyMapFirstThenHalvesPreservingKeys(): void
    {
        $node = Trees::generateWhere(
            new DictionaryArbitrary(new StringArbitrary(5, 5), new IntArbitrary(0, 10), 0, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );
        $value = $node->value;
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], []);
        Assert::same($candidates[1], array_slice($value, 0, 2, preserve_keys: true));
        Assert::same($candidates[2], array_slice($value, 0, 1, preserve_keys: true));
    }

    public function shrinkYieldsTheEmptyMapExactlyOnce(): void
    {
        // The empty map comes only from the minSize===0 guard; the size loop must
        // stop at 1 and never emit a second [].
        $node = Trees::generateWhere(
            new DictionaryArbitrary(new StringArbitrary(5, 5), new IntArbitrary(0, 10), 0, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        $empties = array_filter(Trees::childValues($node), static fn(mixed $candidate): bool => $candidate === []);
        Assert::same(count($empties), 1);
    }

    public function shrinkReducesValuesInPlaceKeepingKeys(): void
    {
        // A fixed size blocks the size phase: the single entry's value shrinks
        // through its own tree while the key stays fixed.
        $node = Trees::generateWhere(
            new DictionaryArbitrary(new StringArbitrary(5, 5), new IntArbitrary(0, 10), 1, 1),
            static fn(mixed $v): bool => is_array($v) && count($v) === 1 && reset($v) === 8,
        );
        $key = array_key_first($node->value);

        Assert::same(Trees::childValues($node), [
            [$key => 0], [$key => 4], [$key => 6], [$key => 7],
        ]);
    }

    public function shrinkValuePhaseKeepsTheOtherEntriesIntact(): void
    {
        // Shrinking one value must replace it inside the full map, not collapse
        // the candidate to a single-entry map.
        $node = Trees::generateWhere(
            new DictionaryArbitrary(new StringArbitrary(5, 5), new IntArbitrary(0, 10), 2, 2),
            static fn(mixed $v): bool => is_array($v) && count($v) === 2 && array_values($v) === [8, 8],
        );
        $value = $node->value;
        [$firstKey, $secondKey] = array_keys($value);

        $candidates = Trees::childValues($node);

        Assert::true(in_array([$firstKey => 0, $secondKey => 8], $candidates, strict: true));
        Assert::true(in_array([$firstKey => 8, $secondKey => 0], $candidates, strict: true));
    }

    public function shrinkKeepsTheMinimumSizeCandidate(): void
    {
        // With minSize 1 the size-floor slice (one entry) must be produced.
        $node = Trees::generateWhere(
            new DictionaryArbitrary(new StringArbitrary(5, 5), new IntArbitrary(), 1, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );
        $value = $node->value;

        Assert::true(in_array(array_slice($value, 0, 1, preserve_keys: true), Trees::childValues($node), strict: true));
    }

    public function shrinkNeverEscapesBelowMinimumSize(): void
    {
        $node = Trees::generateWhere(
            new DictionaryArbitrary(new StringArbitrary(5, 5), new IntArbitrary(0, 5), 1, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::true(count($candidate) >= 1);
        }
    }

    public function shrinkOfEmptyMapYieldsNothing(): void
    {
        $node = Trees::generateWhere(
            new DictionaryArbitrary(new StringArbitrary(1, 5), new IntArbitrary(), 0, 3),
            static fn(mixed $v): bool => $v === [],
        );

        Assert::same(Trees::childValues($node), []);
    }

    public function generateSupportsIntegerKeys(): void
    {
        $arbitrary = new DictionaryArbitrary(new IntArbitrary(1, 1000), new BoolArbitrary(), 3, 3);
        $dictionary = $arbitrary->generate(new Random(1))->value;

        foreach (array_keys($dictionary) as $key) {
            Assert::true(is_int($key));
        }
    }

    public function keyDrawBudgetIsExactlyTenPerRequestedEntry(): void
    {
        // A constant key can never fill a size of 2: the collision loop must
        // stop after exactly size * 10 = 20 key draws (budget strictly
        // positive) and then report exhaustion — an off-by-one budget check
        // would draw a 21st key.
        $key = new class implements ArbitraryInterface {
            public int $draws = 0;

            #[\Override]
            public function generate(Random $random): Shrinkable
            {
                ++$this->draws;

                return Shrinkable::leaf('k');
            }
        };
        $arbitrary = new DictionaryArbitrary($key, new ConstantArbitrary(1), 2, 2);

        try {
            $arbitrary->generate(new Random(1));

            Assert::fail('expected GenerationExhausted');
        } catch (GenerationExhausted) {
        }

        Assert::same($key->draws, 20);
    }

    public function duplicateKeyDrawIsSkippedNotFatal(): void
    {
        // Key stream 'a', 'a', 'b': the duplicate second draw must be skipped
        // (continue) and generation must keep filling the map with 'b' —
        // bailing out of the loop on a collision would under-fill it.
        $key = new class implements ArbitraryInterface {
            private const array KEYS = ['a', 'a', 'b'];

            private int $index = 0;

            #[\Override]
            public function generate(Random $random): Shrinkable
            {
                return Shrinkable::leaf(self::KEYS[$this->index++ % 3]);
            }
        };
        $arbitrary = new DictionaryArbitrary($key, new ConstantArbitrary(1), 2, 2);

        Assert::same($arbitrary->generate(new Random(1))->value, ['a' => 1, 'b' => 1]);
    }

    #[ExpectException(GenerationExhausted::class)]
    public function throwsWhenTheKeySpaceCannotReachTheMinimumSize(): void
    {
        // Two possible keys can never fill a minimum of 5 distinct keys —
        // generation is exhausted instead of silently under-filling the map.
        (new DictionaryArbitrary(new IntArbitrary(1, 2), new IntArbitrary(), 5, 8))->generate(new Random(1));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsKeyArbitraryProducingNonArrayKey(): void
    {
        // A bool key is neither int nor string and cannot index a PHP array.
        (new DictionaryArbitrary(new BoolArbitrary(), new IntArbitrary(), 1, 1))->generate(new Random(1));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeMinimumSize(): void
    {
        new DictionaryArbitrary(new StringArbitrary(1, 5), new IntArbitrary(), -1, 5);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsZeroMaximumSize(): void
    {
        new DictionaryArbitrary(new StringArbitrary(1, 5), new IntArbitrary(), 0, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvertedSize(): void
    {
        new DictionaryArbitrary(new StringArbitrary(1, 5), new IntArbitrary(), 10, 2);
    }
}
