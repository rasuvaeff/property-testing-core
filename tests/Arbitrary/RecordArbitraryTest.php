<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\BoolArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\RecordArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RecordArbitrary::class)]
final class RecordArbitraryTest
{
    public function generateProducesOneValuePerFieldKeyedByName(): void
    {
        $arbitrary = new RecordArbitrary([
            'age' => new IntArbitrary(30, 30),
            'active' => new BoolArbitrary(),
        ]);

        $record = $arbitrary->generate(new Random(1))->value;

        Assert::same($record['age'], 30);
        Assert::true(is_bool($record['active']));
        Assert::same(array_keys($record), ['age', 'active']);
    }

    public function generateDrawsEachFieldFromItsOwnArbitrary(): void
    {
        $arbitrary = new RecordArbitrary([
            'x' => new IntArbitrary(7, 7),
            'y' => new IntArbitrary(9, 9),
        ]);

        Assert::same($arbitrary->generate(new Random(1))->value, ['x' => 7, 'y' => 9]);
    }

    public function shrinkReducesOneFieldAtATimeThroughItsTree(): void
    {
        // 'x' is pinned (its own target), so candidates are exactly y's ladder.
        $node = Trees::generateWhere(
            new RecordArbitrary([
                'x' => new IntArbitrary(5, 5),
                'y' => new IntArbitrary(0, 10),
            ]),
            static fn(mixed $v): bool => $v === ['x' => 5, 'y' => 8],
        );

        Assert::same(Trees::childValues($node), [
            ['x' => 5, 'y' => 0],
            ['x' => 5, 'y' => 4],
            ['x' => 5, 'y' => 6],
            ['x' => 5, 'y' => 7],
        ]);
    }

    public function shrinkReducesEachFieldThroughItsArbitrary(): void
    {
        $node = Trees::generateWhere(
            new RecordArbitrary([
                'x' => new IntArbitrary(0, 10),
                'y' => new IntArbitrary(0, 10),
            ]),
            static fn(mixed $v): bool => $v === ['x' => 8, 'y' => 8],
        );
        $candidates = Trees::childValues($node);

        Assert::true(in_array(['x' => 0, 'y' => 8], $candidates, strict: true));
        Assert::true(in_array(['x' => 8, 'y' => 0], $candidates, strict: true));
    }

    public function shrinkKeepsTheKeySetFixed(): void
    {
        $node = Trees::generateWhere(
            new RecordArbitrary([
                'x' => new IntArbitrary(0, 10),
                'y' => new IntArbitrary(0, 10),
            ]),
            static fn(mixed $v): bool => $v === ['x' => 8, 'y' => 8],
        );

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::same(array_keys($candidate), ['x', 'y']);
        }
    }

    public function fullyShrunkRecordIsTerminal(): void
    {
        $node = Trees::generateWhere(
            new RecordArbitrary([
                'x' => new IntArbitrary(0, 10),
                'y' => new IntArbitrary(0, 10),
            ]),
            static fn(mixed $v): bool => $v === ['x' => 0, 'y' => 0],
        );

        Assert::same(Trees::childValues($node), []);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyShape(): void
    {
        new RecordArbitrary([]);
    }
}
