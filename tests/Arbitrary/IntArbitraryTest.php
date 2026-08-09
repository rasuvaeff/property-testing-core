<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(IntArbitrary::class)]
final class IntArbitraryTest
{
    public function generateStaysWithinInclusiveRange(): void
    {
        $arbitrary = new IntArbitrary(5, 10);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true($value >= 5 && $value <= 10);
        }
    }

    public function shrinkTriesZeroFirst(): void
    {
        $node = Trees::generateWhere(new IntArbitrary(-1000, 1000), static fn(mixed $v): bool => $v !== 0);

        Assert::same(Trees::childValues($node)[0], 0);
    }

    public function shrinkHalvesTheDistanceToTheTarget(): void
    {
        // Candidates for 8 (target 0): the target, then 8 - 4, 8 - 2, 8 - 1 —
        // a binary-search ladder from the most aggressive candidate upward.
        $node = Trees::generateWhere(new IntArbitrary(0, 16), static fn(mixed $v): bool => $v === 8);

        Assert::same(Trees::childValues($node), [0, 4, 6, 7]);
    }

    public function shrinkCandidatesCarryTheirOwnSubtrees(): void
    {
        // Descending into candidate 4 must offer its own ladder toward 0.
        $node = Trees::generateWhere(new IntArbitrary(0, 16), static fn(mixed $v): bool => $v === 8);

        $children = [];
        foreach ($node->shrinks() as $child) {
            $children[] = $child;
        }

        Assert::same($children[1]->value, 4);
        Assert::same(Trees::childValues($children[1]), [0, 2, 3]);
    }

    public function shrinkOfNegativeValueStaysBetweenTargetAndValue(): void
    {
        $node = Trees::generateWhere(new IntArbitrary(-16, 0), static fn(mixed $v): bool => $v === -8);

        Assert::same(Trees::childValues($node), [0, -4, -6, -7]);
    }

    public function shrinkOfZeroYieldsNothing(): void
    {
        $node = Trees::generateWhere(new IntArbitrary(-1000, 1000), static fn(mixed $v): bool => $v === 0);

        Assert::same(Trees::childValues($node), []);
    }

    public function shrinkCandidatesAreClampedToConfiguredRange(): void
    {
        $node = Trees::generateWhere(new IntArbitrary(50, 100), static fn(mixed $v): bool => $v === 80);
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], 50);

        foreach ($candidates as $candidate) {
            Assert::true($candidate >= 50 && $candidate <= 100);
        }
    }

    public function greedyDescentFindsTheMinimalFailingValue(): void
    {
        // The runner's per-parameter loop: for the monotone predicate "> 50" the
        // binary-search ladder must land exactly on 51.
        $node = Trees::generateWhere(new IntArbitrary(0, 100), static fn(mixed $v): bool => is_int($v) && $v > 50);
        $minimal = Trees::descendWhile($node, static fn(mixed $v): bool => is_int($v) && $v > 50);

        Assert::same($minimal->value, 51);
    }

    public function acceptsAndGeneratesADegenerateRange(): void
    {
        // min === max is a valid (single-value) range and must construct.
        $arbitrary = new IntArbitrary(7, 7);

        Assert::same($arbitrary->generate(new Random(1))->value, 7);
    }

    public function degenerateRangeValueIsItsOwnTargetAndDoesNotShrink(): void
    {
        Assert::same(Trees::childValues((new IntArbitrary(7, 7))->generate(new Random(1))), []);
    }

    public function generateReachesBothExactBounds(): void
    {
        // Pins that the configured min and max are themselves reachable, killing
        // off-by-one mutations of the range bounds in generate().
        $arbitrary = new IntArbitrary(3, 6);
        $random = new Random(1);
        $min = PHP_INT_MAX;
        $max = PHP_INT_MIN;

        for ($i = 0; $i < 200; ++$i) {
            $value = $arbitrary->generate($random)->value;
            $min = min($min, $value);
            $max = max($max, $value);
        }

        Assert::same($min, 3);
        Assert::same($max, 6);
    }

    public function generateBiasesTowardBoundaryValues(): void
    {
        // Under pure uniform sampling these edge values would essentially never
        // appear (~3 in a million draws); the bias makes them frequent.
        $arbitrary = new IntArbitrary(0, 1_000_000);
        $random = new Random(1);
        $boundaryHits = 0;

        for ($i = 0; $i < 1000; ++$i) {
            if (in_array($arbitrary->generate($random)->value, [0, 1, 1_000_000], strict: true)) {
                ++$boundaryHits;
            }
        }

        // ~1 draw in 5 is a boundary (~200 of 1000); the band also rules out an
        // inverted condition that would bias ~4 in 5.
        Assert::true($boundaryHits > 100 && $boundaryHits < 400);
    }

    public function generateSequenceIsPinnedForAFixedSeed(): void
    {
        // Pins the exact interleaving of the bias draw (int(1, 5) === 1) with
        // the value draws: widening the bias range to start at 0 or moving the
        // comparison to another constant reshuffles which draws take the
        // boundary branch, so the whole sequence diverges.
        $arbitrary = new IntArbitrary(0, 1000);
        $random = new Random(1);
        $values = [];

        for ($i = 0; $i < 12; ++$i) {
            $values[] = $arbitrary->generate($random)->value;
        }

        Assert::same($values, [1000, 66, 573, 782, 451, 650, 544, 677, 626, 470, 0, 538]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvertedRange(): void
    {
        new IntArbitrary(10, 5);
    }
}
