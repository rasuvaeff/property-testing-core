<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\UniqueArrayArbitrary;
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
#[Covers(UniqueArrayArbitrary::class)]
final class UniqueArrayArbitraryTest
{
    /**
     * @param list<mixed> $values
     */
    private function isDistinct(array $values): bool
    {
        foreach ($values as $index => $value) {
            $others = $values;
            unset($others[$index]);

            if (in_array($value, $others, strict: true)) {
                return false;
            }
        }

        return true;
    }

    public function generateProducesPairwiseDistinctElements(): void
    {
        $arbitrary = new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 0, 20);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            Assert::true($this->isDistinct($arbitrary->generate($random)->value));
        }
    }

    public function generateStaysWithinSizeRangeWhenTheElementSpaceIsLarge(): void
    {
        $arbitrary = new UniqueArrayArbitrary(new IntArbitrary(), 2, 8);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $count = count($arbitrary->generate($random)->value);

            Assert::true($count >= 2 && $count <= 8);
        }
    }

    public function settlesForFewerElementsWhenTheSpaceRunsDry(): void
    {
        // Only 3 distinct values exist; a drawn size above 3 must settle for at
        // most 3 (minSize 0 keeps that legal) instead of looping forever.
        $arbitrary = new UniqueArrayArbitrary(new IntArbitrary(1, 3), 0, 10);
        $random = new Random(1);

        for ($i = 0; $i < 100; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true(count($value) <= 3);
            Assert::true($this->isDistinct($value));
        }
    }

    #[ExpectException(GenerationExhausted::class)]
    public function throwsWhenTheElementSpaceCannotReachTheMinimumSize(): void
    {
        // Two distinct values can never fill a minimum of 3 — generation is
        // exhausted and reported instead of silently under-filling.
        $arbitrary = new UniqueArrayArbitrary(new IntArbitrary(1, 2), 3, 5);
        $random = new Random(1);

        for ($i = 0; $i < 100; ++$i) {
            $arbitrary->generate($random);
        }
    }

    public function shrinkTriesEmptyArrayFirstThenExactHalfPrefixes(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 0, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );
        $value = $node->value;
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], []);
        Assert::same($candidates[1], array_slice($value, 0, 2));
        Assert::same($candidates[2], array_slice($value, 0, 1));
    }

    public function everyShrinkCandidateStaysPairwiseDistinct(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 50), 0, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) >= 3,
        );

        foreach (Trees::valuesToDepth($node, 3) as $candidate) {
            Assert::true($this->isDistinct($candidate));
        }
    }

    public function elementShrinkCandidatesCollidingWithAnotherElementAreSkipped(): void
    {
        // Element 8 shrinks toward 0 first, but 0 is already in the list: the
        // colliding candidate must be dropped while the rest of the ladder
        // ([4, 0], [6, 0], [7, 0]) survives.
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 10), 2, 2),
            static fn(mixed $v): bool => $v === [8, 0],
        );
        $candidates = Trees::childValues($node);

        Assert::false(in_array([0, 0], $candidates, strict: true));
        Assert::true(in_array([4, 0], $candidates, strict: true));
    }

    public function acceptsMaximumSizeOfOne(): void
    {
        // maxSize === 1 is valid (the boundary of the "at least 1" rule).
        $value = (new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 1, 1))->generate(new Random(1))->value;

        Assert::same(count($value), 1);
    }

    public function exhaustsExactlyTheDrawBudgetWhenTheSpaceIsASingleton(): void
    {
        // One distinct value, fixed drawn size 5: the first draw is accepted,
        // every further draw collides, and the loop stops after exactly
        // size * 10 = 50 draws — pins the budget bounds. The under-filled
        // minimum then throws.
        $inner = new class implements ArbitraryInterface {
            public int $calls = 0;

            #[\Override]
            public function generate(Random $random): Shrinkable
            {
                ++$this->calls;

                return Shrinkable::leaf(1);
            }
        };

        try {
            (new UniqueArrayArbitrary($inner, 5, 5))->generate(new Random(1));

            Assert::fail('expected a GenerationExhausted');
        } catch (GenerationExhausted $e) {
            Assert::string($e->getMessage())->contains('distinct value');
        }

        Assert::same($inner->calls, 50);
    }

    public function shrinkYieldsTheEmptyArrayExactlyOnce(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 0, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        $empties = array_filter(Trees::childValues($node), static fn(mixed $candidate): bool => $candidate === []);
        Assert::same(count($empties), 1);
    }

    public function shrinkKeepsTheMinimumSizeCandidate(): void
    {
        // With minSize 1 the size-floor slice (one element) must be produced.
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 1, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        Assert::true(in_array(array_slice($node->value, 0, 1), Trees::childValues($node), strict: true));
    }

    public function shrinkNeverEscapesBelowMinimumSize(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 1, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::true(count($candidate) >= 1);
        }
    }

    public function shrinkOfEmptyArrayYieldsNothing(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(), 0, 3),
            static fn(mixed $v): bool => $v === [],
        );

        Assert::same(Trees::childValues($node), []);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeMinimumSize(): void
    {
        new UniqueArrayArbitrary(new IntArbitrary(), -1, 5);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsZeroMaximumSize(): void
    {
        new UniqueArrayArbitrary(new IntArbitrary(), 0, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvertedSize(): void
    {
        new UniqueArrayArbitrary(new IntArbitrary(), 10, 2);
    }
}
