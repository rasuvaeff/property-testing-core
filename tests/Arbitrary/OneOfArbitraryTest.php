<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\OneOfArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OneOfArbitrary::class)]
final class OneOfArbitraryTest
{
    public function generatePicksOnlyFromGivenValues(): void
    {
        $values = ['a', 'b', 'c'];
        $arbitrary = new OneOfArbitrary(...$values);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            Assert::true(in_array($arbitrary->generate($random)->value, $values, strict: true));
        }
    }

    public function namedArgumentsArePickedByPosition(): void
    {
        // A string-keyed variadic (named arguments, a spread map) must not
        // leave the enumeration looking for index 0 that no longer exists.
        $arbitrary = new OneOfArbitrary(...['ok' => 'a', 'err' => 'b']);
        $random = new Random(1);
        $seen = [];

        for ($i = 0; $i < 30; ++$i) {
            $seen[$arbitrary->generate($random)->value] = true;
        }

        $values = array_keys($seen);
        sort($values);

        Assert::same($values, ['a', 'b']);
    }

    public function generateCanProduceEveryValueIncludingTheEndpoints(): void
    {
        // Every index in [0, count - 1] must be reachable, so the first and last
        // values appear; this pins the index range against off-by-one mutations.
        $arbitrary = new OneOfArbitrary('a', 'b', 'c');
        $random = new Random(1);
        $seen = [];

        for ($i = 0; $i < 200; ++$i) {
            $seen[$arbitrary->generate($random)->value] = true;
        }

        Assert::same(isset($seen['a'], $seen['b'], $seen['c']), expected: true);
    }

    public function shrinkYieldsOnlyEarlierValues(): void
    {
        // Earlier values are "smaller": the middle value shrinks to the first
        // one only, never sideways to the last — this is what guarantees the
        // shrink index strictly decreases and the loop terminates.
        $node = Trees::generateWhere(new OneOfArbitrary('x', 'y', 'z'), static fn(mixed $v): bool => $v === 'y');

        Assert::same(Trees::childValues($node), ['x']);
    }

    public function lastValueShrinksThroughAllEarlierDistinctValues(): void
    {
        $node = Trees::generateWhere(new OneOfArbitrary('x', 'y', 'z'), static fn(mixed $v): bool => $v === 'z');

        Assert::same(Trees::childValues($node), ['x', 'y']);
    }

    public function firstValueDoesNotShrink(): void
    {
        $node = Trees::generateWhere(new OneOfArbitrary('x', 'y', 'z'), static fn(mixed $v): bool => $v === 'x');

        Assert::same(Trees::childValues($node), []);
    }

    public function candidatesCarryTheirOwnEarlierOnlySubtrees(): void
    {
        // Descending into 'y' (from 'z') offers 'x'; descending into 'x' ends.
        $node = Trees::generateWhere(new OneOfArbitrary('x', 'y', 'z'), static fn(mixed $v): bool => $v === 'z');

        $children = [];
        foreach ($node->shrinks() as $child) {
            $children[] = $child;
        }

        Assert::same($children[1]->value, 'y');
        Assert::same(Trees::childValues($children[1]), ['x']);
        Assert::same(Trees::childValues($children[0]), []);
    }

    public function shrinkScansPastAnEqualEarlierValue(): void
    {
        // For the value 9 drawn at index 2 of (9, 5, 9), index 0 equals the
        // current value: the scan must skip it (continue, not break) and still
        // yield the distinct 5. Nodes for index 0 are terminal, so observing
        // children [5] proves the index-2 scan got past the equal value.
        $arbitrary = new OneOfArbitrary(9, 5, 9);
        $sawIndexTwoShrink = false;

        for ($seed = 0; $seed < 100; ++$seed) {
            $node = $arbitrary->generate(new Random($seed));

            if ($node->value === 9 && Trees::childValues($node) === [5]) {
                $sawIndexTwoShrink = true;

                break;
            }
        }

        Assert::true($sawIndexTwoShrink);
    }

    public function shrinkScansPastADuplicateToLaterDistinctValues(): void
    {
        // From 7 the scan hits 5, its duplicate (skipped), then must continue
        // to the distinct 3 — dedup uses continue, not break.
        $node = Trees::generateWhere(new OneOfArbitrary(5, 5, 3, 7), static fn(mixed $v): bool => $v === 7);

        Assert::same(Trees::childValues($node), [5, 3]);
    }

    public function shrinkDeduplicatesIdenticalCandidates(): void
    {
        $node = Trees::generateWhere(new OneOfArbitrary(1, 1, 2), static fn(mixed $v): bool => $v === 2);

        Assert::same(Trees::childValues($node), [1]);
    }

    public function shrinkEnumeratesObjectCandidatesByIdentity(): void
    {
        // Deduplication uses the same identity the skip does, so two distinct
        // objects are two candidates however alike their state — and a cyclic
        // graph, which var_export() cannot print at all, is enumerated like any
        // other value.
        $first = new \stdClass();
        $first->self = $first;
        $second = new \stdClass();
        $second->self = $second;
        $third = new \stdClass();

        $node = Trees::generateWhere(
            new OneOfArbitrary($first, $second, $third),
            static fn(mixed $v): bool => $v === $third,
        );

        Assert::same(Trees::childValues($node), [$first, $second]);
    }

    public function shrinkDeduplicatesTheSameObjectListedTwice(): void
    {
        $object = new \stdClass();
        $node = Trees::generateWhere(
            new OneOfArbitrary($object, $object, 'last'),
            static fn(mixed $v): bool => $v === 'last',
        );

        Assert::same(Trees::childValues($node), [$object]);
    }

    public function shrinkSkipsEarlierValuesEqualToTheCurrentOne(): void
    {
        // The value 5 at index 1 must not offer the identical 5 at index 0.
        $arbitrary = new OneOfArbitrary(5, 5, 7);
        $random = new Random(1);

        for ($i = 0; $i < 100; ++$i) {
            $node = $arbitrary->generate($random);

            if ($node->value === 5) {
                Assert::same(Trees::childValues($node), []);
            } else {
                Assert::same(Trees::childValues($node), [5]);
            }
        }
    }

    /**
     * `oneOf(generator, generator)` is how fast-check, jqwik and Hypothesis
     * spell "pick one of these generators"; here it would make the generator
     * objects the data, and the property would pass without the body ever
     * seeing a generated value. A green test that checked nothing is worse
     * than a rejected call.
     */
    public function rejectsAGeneratorAmongTheValues(): void
    {
        try {
            new OneOfArbitrary(new ConstantArbitrary('a'), 'b');
        } catch (\InvalidArgumentException $exception) {
            Assert::same(
                $exception->getMessage(),
                'OneOf takes values, not generators, and was given '
                . ConstantArbitrary::class
                . '. Use Gen::frequency() to pick between generators, or pass the values themselves',
            );

            return;
        }

        Assert::fail('Expected a generator among the values to be rejected');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAGeneratorInAnyPosition(): void
    {
        // The check walks every value, not just the first: a generator hidden
        // behind two plain values is the same silent pass.
        new OneOfArbitrary('a', 'b', new ConstantArbitrary('c'));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyValueSet(): void
    {
        new OneOfArbitrary();
    }

    public function variantCountIsTheNumberOfValues(): void
    {
        Assert::same((new OneOfArbitrary('a', 'b', 'c'))->variantCount(), 3);
    }

    public function restrictingToVariantsKeepsTheChosenValuesInTheGivenOrder(): void
    {
        // Order matters beyond bookkeeping: OneOf shrinks toward earlier
        // values, so the restricted copy's first kept value is what its
        // descents gravitate to.
        $restricted = (new OneOfArbitrary('a', 'b', 'c', 'd'))->withVariants([1, 3]);
        $random = new Random(2);
        $seen = [];

        for ($i = 0; $i < 100; ++$i) {
            $seen[$restricted->generate($random)->value] = true;
        }

        Assert::same(array_keys($seen), ['b', 'd']);
        Assert::same($restricted->variantCount(), 2);
    }

    public function restrictingKeepsAValueThatIsNull(): void
    {
        // A null variant is a value like any other; a lookup that treated it
        // as "absent" would drop it and report an out-of-range index instead.
        $restricted = (new OneOfArbitrary(null, 'a'))->withVariants([0]);

        Assert::null($restricted->generate(new Random(4))->value);
    }

    public function rejectsAVariantIndexOutsideTheValues(): void
    {
        try {
            (new OneOfArbitrary('a', 'b'))->withVariants([2]);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Variant 2 is outside the 2 values of this generator');
        }
    }
}
