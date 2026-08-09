<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ConstantArbitrary::class)]
final class ConstantArbitraryTest
{
    public function generateAlwaysReturnsTheSameValue(): void
    {
        $arbitrary = new ConstantArbitrary('fixed');
        $random = new Random(1);

        Assert::same($arbitrary->generate($random)->value, 'fixed');
        Assert::same($arbitrary->generate($random)->value, 'fixed');
    }

    public function generatePreservesValueType(): void
    {
        Assert::same((new ConstantArbitrary(42))->generate(new Random(1))->value, 42);
        Assert::same((new ConstantArbitrary(null))->generate(new Random(1))->value, null);
        Assert::same((new ConstantArbitrary([1, 2]))->generate(new Random(1))->value, [1, 2]);
    }

    public function shrinkYieldsNothing(): void
    {
        Assert::same(Trees::childValues((new ConstantArbitrary('fixed'))->generate(new Random(1))), []);
    }
}
