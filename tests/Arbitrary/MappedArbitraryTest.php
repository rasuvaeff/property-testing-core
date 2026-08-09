<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\MappedArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MappedArbitrary::class)]
final class MappedArbitraryTest
{
    public function generateAppliesTheMapping(): void
    {
        $arbitrary = new MappedArbitrary(new IntArbitrary(1, 10), static fn(int $x): int => $x * 2);
        $random = new Random(1);

        for ($i = 0; $i < 100; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true($value >= 2 && $value <= 20 && $value % 2 === 0);
        }
    }

    public function mappedValuesShrinkThroughTheInnerTree(): void
    {
        // The headline 2.0 fix: shrinking happens in the source domain and the
        // mapping is re-applied, so a doubled int shrinks along the doubled
        // ladder (source 8 shrinks by [0, 4, 6, 7] -> mapped [0, 8, 12, 14]).
        $node = Trees::generateWhere(
            new MappedArbitrary(new IntArbitrary(0, 10), static fn(int $x): int => $x * 2),
            static fn(mixed $v): bool => $v === 16,
        );

        Assert::same(Trees::childValues($node), [0, 8, 12, 14]);
    }

    public function mappedCandidatesCarryMappedSubtrees(): void
    {
        // Descending into a candidate keeps shrinking in the source domain.
        $node = Trees::generateWhere(
            new MappedArbitrary(new IntArbitrary(0, 10), static fn(int $x): int => $x * 2),
            static fn(mixed $v): bool => $v === 16,
        );

        $children = [];
        foreach ($node->shrinks() as $child) {
            $children[] = $child;
        }

        // children[1] maps source 4: its ladder is [0, 2, 3] -> mapped [0, 4, 6].
        Assert::same($children[1]->value, 8);
        Assert::same(Trees::childValues($children[1]), [0, 4, 6]);
    }

    public function greedyDescentMinimisesInTheMappedDomain(): void
    {
        // The runner's loop over a mapped tree lands on the minimal even value
        // still failing "> 50": source minimises to 26, mapped to 52.
        $arbitrary = new MappedArbitrary(new IntArbitrary(0, 100), static fn(int $x): int => $x * 2);
        $node = Trees::generateWhere($arbitrary, static fn(mixed $v): bool => is_int($v) && $v > 50);
        $minimal = Trees::descendWhile($node, static fn(mixed $v): bool => is_int($v) && $v > 50);

        Assert::same($minimal->value, 52);
    }
}
