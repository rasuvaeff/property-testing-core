<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Arbitrary\FrequencyArbitrary;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\LeafFallbackArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(LeafFallbackArbitrary::class)]
final class LeafFallbackArbitraryTest
{
    public function aWrappedValueShrinksToTheLeafFirstThenThroughItsOwnTree(): void
    {
        $choice = new FrequencyArbitrary([[1, Gen::constant(0)], [1, Gen::intBetween(10, 20)]]);
        $arbitrary = new LeafFallbackArbitrary($choice, Gen::constant(-1));

        for ($seed = 1; ; ++$seed) {
            $node = $arbitrary->generate(new Random($seed));

            if ($node->value >= 12) {
                break;
            }
        }

        $replay = new Random($seed);
        $replay->int(PHP_INT_MIN, PHP_INT_MAX);
        $own = Trees::childValues($choice->generate($replay));

        Assert::true(count($own) > 1);
        Assert::same(Trees::childValues($node), [-1, ...$own]);
    }
}
