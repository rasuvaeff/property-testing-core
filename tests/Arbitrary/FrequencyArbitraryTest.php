<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\BoolArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FrequencyArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(FrequencyArbitrary::class)]
final class FrequencyArbitraryTest
{
    public function generatePicksOnlyValuesProducibleByABranch(): void
    {
        $arbitrary = new FrequencyArbitrary([
            [1, new IntArbitrary(0, 0)],
            [1, new IntArbitrary(1, 1)],
        ]);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            Assert::true(in_array($arbitrary->generate($random)->value, [0, 1], strict: true));
        }
    }

    public function generateReachesEveryBranch(): void
    {
        // Equal weights: every branch must be selectable, so all three distinct
        // values appear. Pins the weighted walk against branch-skipping mutants.
        $arbitrary = new FrequencyArbitrary([
            [1, new IntArbitrary(0, 0)],
            [1, new IntArbitrary(1, 1)],
            [1, new IntArbitrary(2, 2)],
        ]);
        $random = new Random(1);
        $seen = [];

        for ($i = 0; $i < 300; ++$i) {
            $seen[$arbitrary->generate($random)->value] = true;
        }

        Assert::same(isset($seen[0], $seen[1], $seen[2]), expected: true);
    }

    public function lowWeightBranchStaysReachableBesideADominantOne(): void
    {
        // A weight-1 branch next to a weight-50 one must still be reachable: the
        // selection boundary is inclusive, so the last unit of weight is hit.
        $arbitrary = new FrequencyArbitrary([
            [50, new IntArbitrary(7, 7)],
            [1, new IntArbitrary(9, 9)],
        ]);
        $random = new Random(1);
        $seen = [];

        for ($i = 0; $i < 400; ++$i) {
            $seen[$arbitrary->generate($random)->value] = true;
        }

        Assert::same(isset($seen[7], $seen[9]), expected: true);
    }

    public function singlePairBehavesLikeTheInnerArbitrary(): void
    {
        $arbitrary = new FrequencyArbitrary([[1, new IntArbitrary(3, 3)]]);

        Assert::same($arbitrary->generate(new Random(1))->value, 3);
    }

    public function shrinkStaysWithinTheGeneratingBranch(): void
    {
        // The value carries its generating branch's tree: an int from the
        // [5, 9] branch shrinks along that branch's ladder (toward 5), and no
        // boolean ever appears among the candidates.
        $arbitrary = new FrequencyArbitrary([
            [1, new IntArbitrary(5, 9)],
            [1, new BoolArbitrary()],
        ]);
        $node = Trees::generateWhere($arbitrary, static fn(mixed $v): bool => is_int($v) && $v > 5);
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], 5);

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::true(is_int($candidate));
        }
    }

    public function boolBranchValueShrinksAsABool(): void
    {
        $arbitrary = new FrequencyArbitrary([
            [1, new IntArbitrary(5, 9)],
            [1, new BoolArbitrary()],
        ]);
        $node = Trees::generateWhere($arbitrary, static fn(mixed $v): bool => $v === true);

        Assert::same(Trees::childValues($node), [false]);
    }

    public function branchSelectionRespectsTheExactWeights(): void
    {
        // Weight 1 beside weight 3: the low branch wins ~1/4 of the picks
        // (exactly 500 of 2000 for this seed). A selection draw that starts at
        // 0 instead of 1 crosses zero before the first weight is spent, which
        // inflates the first branch to ~2/5 (~800 picks).
        $arbitrary = new FrequencyArbitrary([
            [1, new ConstantArbitrary('a')],
            [3, new ConstantArbitrary('b')],
        ]);
        $random = new Random(1);
        $firstBranchPicks = 0;

        for ($i = 0; $i < 2000; ++$i) {
            if ($arbitrary->generate($random)->value === 'a') {
                ++$firstBranchPicks;
            }
        }

        Assert::true($firstBranchPicks > 400 && $firstBranchPicks < 600);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyPairs(): void
    {
        new FrequencyArbitrary([]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsZeroWeight(): void
    {
        new FrequencyArbitrary([[0, new IntArbitrary()]]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeWeight(): void
    {
        new FrequencyArbitrary([[-1, new IntArbitrary()]]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonArbitraryInPair(): void
    {
        new FrequencyArbitrary([[1, 'not-an-arbitrary']]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsPairMissingTheArbitrary(): void
    {
        new FrequencyArbitrary([[1]]);
    }

    public function rejectsNonArrayPairWithAStructuralMessage(): void
    {
        // The structural guard must be the one that fires (not a downstream
        // weight/arbitrary check), so the failure points at the pair shape.
        try {
            new FrequencyArbitrary([42]);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('pair must be');
        }
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonIntegerWeight(): void
    {
        new FrequencyArbitrary([[1.5, new IntArbitrary()]]);
    }
}
