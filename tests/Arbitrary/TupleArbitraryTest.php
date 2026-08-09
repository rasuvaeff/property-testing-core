<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\BoolArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\TupleArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TupleArbitrary::class)]
final class TupleArbitraryTest
{
    public function generateProducesOneValuePerElementInOrder(): void
    {
        $arbitrary = new TupleArbitrary(new IntArbitrary(5, 5), new IntArbitrary(9, 9));

        Assert::same($arbitrary->generate(new Random(1))->value, [5, 9]);
    }

    public function generateReindexesNamedVariadicArgumentsToAList(): void
    {
        // Named arguments collect into the variadic string-keyed; the tuple must
        // re-index them to a positional list so the result is a plain list.
        $arbitrary = new TupleArbitrary(first: new IntArbitrary(5, 5), second: new IntArbitrary(9, 9));

        Assert::same($arbitrary->generate(new Random(1))->value, [5, 9]);
    }

    public function generateLengthMatchesArity(): void
    {
        $arbitrary = new TupleArbitrary(new IntArbitrary(), new IntArbitrary(), new BoolArbitrary());

        Assert::same(count($arbitrary->generate(new Random(1))->value), 3);
    }

    public function generateDrawsEachElementFromItsOwnArbitrary(): void
    {
        // Heterogeneous elements: an int and a bool, each from its own arbitrary.
        $arbitrary = new TupleArbitrary(new IntArbitrary(42, 42), new BoolArbitrary());
        $tuple = $arbitrary->generate(new Random(1))->value;

        Assert::same($tuple[0], 42);
        Assert::true(is_bool($tuple[1]));
    }

    public function shrinkReducesOnePositionAtATimeThroughItsTree(): void
    {
        // The first element is pinned (its own target), so candidates are exactly
        // the second position's ladder.
        $node = Trees::generateWhere(
            new TupleArbitrary(new IntArbitrary(5, 5), new IntArbitrary(0, 10)),
            static fn(mixed $v): bool => $v === [5, 8],
        );

        Assert::same(Trees::childValues($node), [[5, 0], [5, 4], [5, 6], [5, 7]]);
    }

    public function shrinkReducesEachPositionThroughItsElement(): void
    {
        $node = Trees::generateWhere(
            new TupleArbitrary(new IntArbitrary(0, 10), new IntArbitrary(0, 10)),
            static fn(mixed $v): bool => $v === [8, 8],
        );
        $candidates = Trees::childValues($node);

        Assert::true(in_array([0, 8], $candidates, strict: true));
        Assert::true(in_array([8, 0], $candidates, strict: true));
    }

    public function shrinkKeepsArityFixed(): void
    {
        $node = Trees::generateWhere(
            new TupleArbitrary(new IntArbitrary(0, 10), new IntArbitrary(0, 10)),
            static fn(mixed $v): bool => $v === [8, 8],
        );

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::same(count($candidate), 2);
        }
    }

    public function fullyShrunkTupleIsTerminal(): void
    {
        $node = Trees::generateWhere(
            new TupleArbitrary(new IntArbitrary(0, 10), new IntArbitrary(0, 10)),
            static fn(mixed $v): bool => $v === [0, 0],
        );

        Assert::same(Trees::childValues($node), []);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyTuple(): void
    {
        new TupleArbitrary();
    }
}
