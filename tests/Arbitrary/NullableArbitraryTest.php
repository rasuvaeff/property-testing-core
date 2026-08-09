<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\NullableArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(NullableArbitrary::class)]
final class NullableArbitraryTest
{
    public function generatesBothNullAndInnerValues(): void
    {
        $arbitrary = new NullableArbitrary(new IntArbitrary());
        $random = new Random(1);

        $sawNull = false;
        $sawInt = false;

        for ($i = 0; $i < 200; ++$i) {
            $value = $arbitrary->generate($random)->value;

            if ($value === null) {
                $sawNull = true;
            } else {
                $sawInt = true;
            }
        }

        Assert::true($sawNull);
        Assert::true($sawInt);
    }

    public function generatesNullExactlyWhenTheDrawIsOne(): void
    {
        // null is produced iff the [0, 1] draw is 1. Check only the first draw of
        // a freshly seeded engine (so the inner generator's draw cannot desync the
        // reference), across many seeds, to pin the branch selection.
        $inner = new IntArbitrary(5, 5);

        for ($seed = 1; $seed <= 50; ++$seed) {
            $firstDraw = (new Random($seed))->int(0, 1);
            $value = (new NullableArbitrary($inner))->generate(new Random($seed))->value;

            Assert::same($value === null, $firstDraw === 1);
        }
    }

    public function shrinkOfNonNullPrefersNullThenDelegates(): void
    {
        $node = Trees::generateWhere(
            new NullableArbitrary(new IntArbitrary(-100, 100)),
            static fn(mixed $v): bool => $v !== null && $v !== 0,
        );
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], null);
        Assert::same($candidates[1], 0);
    }

    public function innerCandidatesStillOfferNullWhenDescended(): void
    {
        // Descending into an inner shrink candidate must keep null on the menu:
        // the wrapper travels with the subtree.
        $node = Trees::generateWhere(
            new NullableArbitrary(new IntArbitrary(-100, 100)),
            static fn(mixed $v): bool => $v !== null && $v !== 0,
        );

        foreach ($node->shrinks() as $child) {
            if ($child->value === null) {
                continue;
            }

            Assert::same(Trees::childValues($child)[0], null);
        }
    }

    public function shrinkOfNullYieldsNothing(): void
    {
        $node = Trees::generateWhere(
            new NullableArbitrary(new IntArbitrary()),
            static fn(mixed $v): bool => $v === null,
        );

        Assert::same(Trees::childValues($node), []);
    }
}
