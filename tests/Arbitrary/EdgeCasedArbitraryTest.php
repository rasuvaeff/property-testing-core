<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\EdgeCasedArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(EdgeCasedArbitrary::class)]
final class EdgeCasedArbitraryTest
{
    public function drawsEveryEdgeValueAtRoughlyOneInFive(): void
    {
        $arbitrary = new EdgeCasedArbitrary(new IntArbitrary(100, 200), [-1, 999]);
        $random = new Random(11);
        $edges = 0;
        $seen = [];

        for ($i = 0; $i < 2000; ++$i) {
            $value = $arbitrary->generate($random)->value;

            if ($value === -1 || $value === 999) {
                ++$edges;
                $seen[$value] = true;
            }
        }

        $seen = array_keys($seen);
        sort($seen);
        Assert::same($seen, [-1, 999]);
        Assert::true($edges > 300 && $edges < 500);
    }

    public function staysOnUnderEdgeCasesNone(): void
    {
        $arbitrary = new EdgeCasedArbitrary(new IntArbitrary(100, 200), [-1]);
        $random = new Random(11, EdgeCases::None);
        $edges = 0;

        for ($i = 0; $i < 500; ++$i) {
            if ($arbitrary->generate($random)->value === -1) {
                ++$edges;
            }
        }

        Assert::true($edges > 50);
    }

    public function leavesTheDelegateSequenceUntouchedForASeed(): void
    {
        // Consume the wrapper's roll by hand: what the delegate then produces
        // must be exactly what it produces unwrapped from the same state.
        $inner = new IntArbitrary(0, 1_000_000);
        $wrapped = new EdgeCasedArbitrary($inner, [-1]);

        $expected = [];
        $bare = new Random(5);
        for ($i = 0; $i < 50; ++$i) {
            $roll = $bare->int(1, 5);
            $expected[] = $roll === 1 ? -1 : $inner->generate($bare)->value;
        }

        $actual = [];
        $random = new Random(5);
        for ($i = 0; $i < 50; ++$i) {
            $actual[] = $wrapped->generate($random)->value;
        }

        Assert::same($actual, $expected);
    }

    public function aGeneratedValueShrinksThroughTheEdgeValuesFirstInOrder(): void
    {
        $node = Trees::generateWhere(
            new EdgeCasedArbitrary(new IntArbitrary(0, 100), [100, 99, 50]),
            static fn(mixed $v): bool => $v === 73,
        );

        Assert::same(array_slice(Trees::childValues($node), 0, 3), [100, 99, 50]);
        Assert::true(in_array(0, Trees::childValues($node), strict: true));
    }

    public function anEdgeValueShrinksToTheEdgeValuesListedBeforeIt(): void
    {
        $node = Trees::generateWhere(
            new EdgeCasedArbitrary(new IntArbitrary(0, 10), [0, 10, 5]),
            static fn(mixed $v): bool => $v === 5,
        );

        Assert::same(Trees::childValues($node), [0, 10]);

        $first = Trees::generateWhere(
            new EdgeCasedArbitrary(new IntArbitrary(0, 10), [0, 10, 5]),
            static fn(mixed $v): bool => $v === 0,
        );
        Assert::same(Trees::childValues($first), []);
    }

    public function skipsAnEdgeCandidateEqualToTheValueItWouldReplace(): void
    {
        $node = Trees::generateWhere(
            new EdgeCasedArbitrary(new IntArbitrary(7, 7), [7, 8]),
            static fn(mixed $v): bool => $v === 7,
        );

        Assert::false(in_array(7, Trees::childValues($node), strict: true));

        Assert::same(Trees::childValues($node)[0], 8);

        $duplicate = Trees::generateWhere(
            new EdgeCasedArbitrary(new IntArbitrary(100, 100), [8, 8]),
            static fn(mixed $v): bool => $v === 8,
        );
        Assert::false(in_array(8, Trees::childValues($duplicate), strict: true));

        // An edge value listed again later (index 2) skips its own duplicate
        // at index 0 and still reaches the value between them.
        $repeated = new EdgeCasedArbitrary(new IntArbitrary(100, 100), [7, 5, 7]);
        $ladders = [];
        for ($seed = 0; $seed < 200; ++$seed) {
            $candidate = $repeated->generate(new Random($seed));

            if ($candidate->value === 7) {
                $ladders[implode(',', Trees::childValues($candidate))] = true;
            }
        }
        // Index 0 has no earlier values, index 2 has exactly [5].
        $keys = array_map(strval(...), array_keys($ladders));
        sort($keys);
        Assert::same($keys, ['', '5']);
    }

    public function everyCandidateOfAGeneratedValueStaysOnTheEdgeThenInnerLadder(): void
    {
        $node = Trees::generateWhere(
            new EdgeCasedArbitrary(new IntArbitrary(0, 100), [100]),
            static fn(mixed $v): bool => $v === 64,
        );

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::true($candidate === 100 || ($candidate >= 0 && $candidate < 64));
        }
    }

    public function shrinkingDescendsToTheFirstEdgeValueWhenItFails(): void
    {
        $node = Trees::generateWhere(
            new EdgeCasedArbitrary(new IntArbitrary(0, 100), [100, 0]),
            static fn(mixed $v): bool => $v === 42,
        );

        Assert::same(Trees::descendWhile($node, static fn(mixed $v): bool => $v >= 42)->value, 100);
    }

    public function rejectsAnEmptyEdgeList(): void
    {
        try {
            new EdgeCasedArbitrary(new IntArbitrary(), []);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Gen::withEdgeCases() requires at least one edge value');
        }
    }
}
