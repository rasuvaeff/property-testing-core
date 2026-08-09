<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\SubsetArbitrary;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Check;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SubsetArbitrary::class)]
final class SubsetArbitraryTest
{
    private const array LETTERS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

    // -- Construction boundaries -------------------------------------------

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonListSource(): void
    {
        new SubsetArbitrary(['x' => 1, 'y' => 2]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsScalarDuplicatesInTheSource(): void
    {
        new SubsetArbitrary([1, 2, 1]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsObjectDuplicatesInTheSource(): void
    {
        $object = new \stdClass();

        new SubsetArbitrary([$object, $object]);
    }

    public function distinguishesIntFromNumericStringMembers(): void
    {
        // 1 and '1' are different set members under strict comparison.
        $subset = new SubsetArbitrary([1, '1'], minSize: 2, maxSize: 2);

        Assert::same($subset->generate(new Random(1))->value, [1, '1']);
    }

    public function acceptsDistinctObjectMembers(): void
    {
        // Distinct instances are distinct set members: construction must not
        // throw and a full-size draw must reproduce them verbatim, in order.
        $first = new \stdClass();
        $second = new \stdClass();
        $subset = new SubsetArbitrary([$first, $second], minSize: 2, maxSize: 2);

        Assert::same($subset->generate(new Random(1))->value, [$first, $second]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnObjectDuplicateSeparatedByAnotherMember(): void
    {
        // The duplicate is NOT adjacent: catching it requires the seen-list to
        // accumulate every previous member, not just the latest one.
        $duplicate = new \stdClass();

        new SubsetArbitrary([$duplicate, new \stdClass(), $duplicate]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeMinimum(): void
    {
        new SubsetArbitrary(self::LETTERS, minSize: -1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAMinimumAboveTheMaximum(): void
    {
        new SubsetArbitrary(self::LETTERS, minSize: 3, maxSize: 2);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAMaximumAboveTheSourceSize(): void
    {
        new SubsetArbitrary(self::LETTERS, maxSize: 9);
    }

    public function emptySourceAlwaysYieldsTheEmptySubset(): void
    {
        Assert::same((new SubsetArbitrary([]))->generate(new Random(7))->value, []);
    }

    public function fullSizeRangePinsTheWholeSet(): void
    {
        $subset = new SubsetArbitrary(self::LETTERS, minSize: 8, maxSize: 8);

        Assert::same($subset->generate(new Random(3))->value, self::LETTERS);
    }

    public function generateIsExactForAFixedSeed(): void
    {
        // Seed 1 draws size 4 and the partial Fisher-Yates lands on a
        // NON-prefix combination: a skipped or degenerate shuffle would always
        // emit the plain prefix ['a', 'b', 'c', 'd'].
        Assert::same((new SubsetArbitrary(self::LETTERS, minSize: 0, maxSize: 8))->generate(new Random(1))->value, ['d', 'e', 'f', 'g']);
    }

    // -- Generation properties ---------------------------------------------

    public function everySubsetIsAnOrderedSelectionWithinTheSizeBounds(): void
    {
        Check::property(
            static function (array $subset): void {
                Assert::true(count($subset) >= 1 && count($subset) <= 5);

                // Distinct members of the source, in source order: the subset
                // must be a subsequence of LETTERS.
                $cursor = 0;
                foreach ($subset as $element) {
                    $position = array_search($element, self::LETTERS, strict: true);

                    Assert::true($position !== false && $position >= $cursor);
                    $cursor = (int) $position + 1;
                }
            },
            ['subset' => Gen::subset(self::LETTERS, minSize: 1, maxSize: 5)],
            runs: 300,
            seed: 20260808,
        );
    }

    public function nullMaximumMeansTheFullSourceSize(): void
    {
        Check::property(
            static function (array $subset): void {
                Assert::true(count($subset) <= count(self::LETTERS));
            },
            ['subset' => Gen::subset(self::LETTERS)],
            runs: 200,
            seed: 20260809,
        );
    }

    public function sameSeedReproducesTheSameSubsetSequence(): void
    {
        $first = new Random(42);
        $second = new Random(42);
        $subset = Gen::subset(range(0, 40), minSize: 0, maxSize: 30);

        for ($draw = 0; $draw < 50; ++$draw) {
            Assert::same($subset->generate($first)->value, $subset->generate($second)->value);
        }
    }

    // -- Shrinking ----------------------------------------------------------

    public function shrinksAFalsifyingSubsetToTheMinimalPrefix(): void
    {
        // "Every subset has at most 2 elements" is falsified by any larger
        // subset; size shrinks to 3, and the position ladders walk the kept
        // elements to the earliest source positions — the minimal
        // counterexample is exactly the 3-element prefix.
        try {
            Check::property(
                static function (array $subset): void {
                    Assert::true(count($subset) <= 2);
                },
                ['subset' => Gen::subset(self::LETTERS, minSize: 0, maxSize: 8)],
                runs: 200,
                seed: 5,
            );

            Assert::true(actual: false);
        } catch (PropertyViolationException $violation) {
            Assert::same($violation->getCounterExample()->shrunkArguments, ['subset' => ['a', 'b', 'c']]);
        }
    }

    public function shrinkingRespectsTheMinimumSize(): void
    {
        try {
            Check::property(
                static function (array $subset): void {
                    Assert::true($subset === []);
                },
                ['subset' => Gen::subset(self::LETTERS, minSize: 2, maxSize: 6)],
                runs: 100,
                seed: 9,
            );

            Assert::true(actual: false);
        } catch (PropertyViolationException $violation) {
            $shrunk = $violation->getCounterExample()->shrunkArguments['subset'];

            Assert::same($shrunk, ['a', 'b']);
        }
    }

    public function shrinkOfTheEmptySubsetYieldsNothing(): void
    {
        // The empty subset is terminal: yielding itself as a candidate would
        // break shrink termination.
        $node = Trees::generateWhere(
            new SubsetArbitrary(self::LETTERS, minSize: 0, maxSize: 8),
            static fn(mixed $v): bool => $v === [],
        );

        Assert::same(Trees::childValues($node), []);
    }

    public function shrinkYieldsTheEmptySubsetExactlyOnce(): void
    {
        // The empty subset comes only from the minSize===0 guard; the halving
        // loop must stop at length 1 and never emit a second [] via a
        // zero-length prefix.
        $node = Trees::generateWhere(
            new SubsetArbitrary(self::LETTERS, minSize: 0, maxSize: 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        $empties = array_filter(Trees::childValues($node), static fn(mixed $candidate): bool => $candidate === []);
        Assert::same(count($empties), 1);
    }

    public function shrinkTriesEmptySubsetThenExactHalfPrefixes(): void
    {
        // Size-first shrinking of a 4-element subset: the empty set, then the
        // exact half prefixes (2 elements, 1 element) of the KEPT selection.
        $node = Trees::generateWhere(
            new SubsetArbitrary(self::LETTERS, minSize: 0, maxSize: 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );
        $value = $node->value;
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], []);
        Assert::same($candidates[1], \array_slice($value, 0, 2));
        Assert::same($candidates[2], \array_slice($value, 0, 1));
    }

    public function halvingStopsAtThePositiveMinimumSize(): void
    {
        // With minSize 2 there is no empty-set guard, so the FIRST candidate
        // is the half prefix of length exactly 2 == minSize — the boundary of
        // the "length >= minSize" halving check.
        $node = Trees::generateWhere(
            new SubsetArbitrary(self::LETTERS, minSize: 2, maxSize: 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        Assert::same(Trees::childValues($node)[0], \array_slice($node->value, 0, 2));
    }

    public function droppingTheFirstElementIsACandidateAtTheMinimumSizeBoundary(): void
    {
        // A 2-element subset at minSize 1, positioned past the first two
        // source letters: the candidate [second] can come only from the
        // single-element-removal phase, keyed by RANK (0, 1) — halving yields
        // [first], position moves keep the size, and unset() by the source
        // positions (both >= 2) would be a silent no-op.
        $node = Trees::generateWhere(
            new SubsetArbitrary(self::LETTERS, minSize: 1, maxSize: 2),
            static fn(mixed $v): bool => is_array($v)
                && count($v) === 2
                && !in_array('a', $v, strict: true)
                && !in_array('b', $v, strict: true),
        );
        [, $second] = $node->value;

        Assert::true(in_array([$second], Trees::childValues($node), strict: true));
    }

    public function positionLadderHalvesTheDistanceToTheFloor(): void
    {
        // A single kept element at source position 5 ('f'), size pinned by
        // minSize 1: the position ladder walks toward the floor (position 0)
        // halving the distance — 'a' (delta 5), 'd' (delta 2), 'e' (delta 1).
        $node = Trees::generateWhere(
            new SubsetArbitrary(self::LETTERS, minSize: 1, maxSize: 1),
            static fn(mixed $v): bool => $v === ['f'],
        );

        Assert::same(Trees::childValues($node), [['a'], ['d'], ['e']]);
    }

    public function shrinkCandidatesReduceSizeBeforeMovingPositions(): void
    {
        $subset = new SubsetArbitrary(self::LETTERS, minSize: 0, maxSize: 8);

        // Find a generated node with at least two elements to inspect.
        $node = null;
        for ($seed = 0; $seed < 100; ++$seed) {
            $candidate = $subset->generate(new Random($seed));

            if (count($candidate->value) >= 2) {
                $node = $candidate;

                break;
            }
        }

        Assert::true($node instanceof \Rasuvaeff\PropertyTesting\Shrinkable);

        $sizes = [];
        foreach ($node->shrinks() as $child) {
            $sizes[] = count($child->value);
        }

        // The first candidate is the empty set; once a same-size candidate
        // appears (position moves), no later candidate is smaller again.
        Assert::same($sizes[0], 0);
        $original = count($node->value);
        $seenSameSize = false;
        foreach ($sizes as $size) {
            if ($size === $original) {
                $seenSameSize = true;
            } elseif ($seenSameSize) {
                Assert::true(actual: false);
            }
        }
    }
}
