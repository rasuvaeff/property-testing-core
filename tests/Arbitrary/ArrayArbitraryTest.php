<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\ArrayArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ArrayArbitrary::class)]
final class ArrayArbitraryTest
{
    public function generateStaysWithinSizeRange(): void
    {
        $arbitrary = new ArrayArbitrary(new IntArbitrary(), 2, 8);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $count = count($arbitrary->generate($random)->value);

            Assert::true($count >= 2 && $count <= 8);
        }
    }

    public function generatesEmptyArrayWhenSizeIsZero(): void
    {
        // range(1, 0) === [1, 0] would make an empty list unreachable; with min size 0
        // an empty array must appear within a reasonable number of draws.
        $arbitrary = new ArrayArbitrary(new IntArbitrary(), 0, 3);
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

    public function shrinkTriesEmptyArrayFirstThenExactHalfPrefixes(): void
    {
        $node = Trees::generateWhere(
            new ArrayArbitrary(new IntArbitrary(0, 10), 0, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );
        $value = $node->value;
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], []);
        Assert::same($candidates[1], array_slice($value, 0, 2));
        Assert::same($candidates[2], array_slice($value, 0, 1));
    }

    public function shrinkElementPhaseShrinksEachPositionInPlace(): void
    {
        // A fixed size blocks the length phase: candidates are exactly the
        // element ladders, one position at a time, via each element's own tree.
        $node = Trees::generateWhere(
            new ArrayArbitrary(new IntArbitrary(0, 10), 2, 2),
            static fn(mixed $v): bool => $v === [8, 8],
        );

        Assert::same(Trees::childValues($node), [
            [0, 8], [4, 8], [6, 8], [7, 8],
            [8, 0], [8, 4], [8, 6], [8, 7],
        ]);
    }

    public function shrinkYieldsTheEmptyArrayExactlyOnce(): void
    {
        // The empty array comes only from the minSize===0 guard; the length loop
        // must stop at 1 and never emit a second [] via a zero-length slice.
        $node = Trees::generateWhere(
            new ArrayArbitrary(new IntArbitrary(0, 10), 0, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        $empties = array_filter(Trees::childValues($node), static fn(mixed $candidate): bool => $candidate === []);
        Assert::same(count($empties), 1);
    }

    public function shrinkNeverEscapesBelowMinimumSize(): void
    {
        // A nonEmptyArrayOf-style generator must never shrink to [] (out of domain).
        $node = Trees::generateWhere(
            new ArrayArbitrary(new IntArbitrary(0, 5), 1, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::true(count($candidate) >= 1);
        }
    }

    public function nonEmptyShrinkKeepsTheMinimumSizeCandidate(): void
    {
        // The length-floor candidate (size === minSize) must be produced.
        $node = Trees::generateWhere(
            new ArrayArbitrary(new IntArbitrary(7, 7), 1, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        Assert::true(in_array([7], Trees::childValues($node), strict: true));
    }

    public function shrinkOfEmptyArrayYieldsNothing(): void
    {
        $node = Trees::generateWhere(
            new ArrayArbitrary(new IntArbitrary(), 0, 3),
            static fn(mixed $v): bool => $v === [],
        );

        Assert::same(Trees::childValues($node), []);
    }

    public function lengthCandidatesCarryTheirOwnElementTrees(): void
    {
        // Descending into a half-length prefix must offer that prefix's element
        // shrinks (the element trees travel with the slice).
        $node = Trees::generateWhere(
            new ArrayArbitrary(new IntArbitrary(8, 8), 0, 8),
            static fn(mixed $v): bool => $v === [8, 8],
        );

        $children = [];
        foreach ($node->shrinks() as $child) {
            $children[] = $child;
        }

        // children[0] is [], children[1] is the one-element prefix [8].
        Assert::same($children[1]->value, [8]);
        Assert::same(Trees::childValues($children[1])[0], []);
    }

    public function generateReachesBothExactSizeBounds(): void
    {
        // The configured min and max sizes must both be reachable.
        $arbitrary = new ArrayArbitrary(new IntArbitrary(), 1, 4);
        $random = new Random(1);
        $minSize = PHP_INT_MAX;
        $maxSize = 0;

        for ($i = 0; $i < 200; ++$i) {
            $size = count($arbitrary->generate($random)->value);
            $minSize = min($minSize, $size);
            $maxSize = max($maxSize, $size);
        }

        Assert::same($minSize, 1);
        Assert::same($maxSize, 4);
    }

    public function generateDrawsElementsFromTheElementArbitrary(): void
    {
        // Elements come from the element arbitrary, not from the index range.
        $arbitrary = new ArrayArbitrary(new IntArbitrary(100, 100), 3, 3);

        Assert::same($arbitrary->generate(new Random(1))->value, [100, 100, 100]);
    }

    public function acceptsMaximumSizeOfOne(): void
    {
        // maxSize === 1 is valid (the boundary of the "at least 1" rule).
        $arbitrary = new ArrayArbitrary(new IntArbitrary(0, 0), 1, 1);

        Assert::same($arbitrary->generate(new Random(1))->value, [0]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeMinimumSize(): void
    {
        new ArrayArbitrary(new IntArbitrary(), -1, 5);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsZeroMaximumSize(): void
    {
        new ArrayArbitrary(new IntArbitrary(), 0, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvertedSize(): void
    {
        new ArrayArbitrary(new IntArbitrary(), 10, 2);
    }
}
