<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Arbitrary\BoolArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\StringArbitrary;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\Domain;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Domain::class)]
final class DomainTest
{
    public function sizeOfAnswersNullForANonEnumerable(): void
    {
        Assert::null(Domain::sizeOf(new StringArbitrary()));
        Assert::same(Domain::sizeOf(new BoolArbitrary()), 2);
    }

    public function productMultipliesAndDeclinesOnAnyUnboundedComponent(): void
    {
        Assert::same(Domain::product([new BoolArbitrary(), new IntArbitrary(0, 9)]), 20);
        Assert::same(Domain::product([]), 1);
        Assert::null(Domain::product([new BoolArbitrary(), new StringArbitrary()]));
    }

    public function productSaturatesInsteadOfOverflowing(): void
    {
        Assert::same(Domain::product([new IntArbitrary(), new BoolArbitrary()]), PHP_INT_MAX);
        Assert::same(Domain::times(PHP_INT_MAX, 2), PHP_INT_MAX);
        Assert::same(Domain::times(intdiv(PHP_INT_MAX, 2), 2), PHP_INT_MAX - 1);
        Assert::same(Domain::times(intdiv(PHP_INT_MAX, 2) + 1, 2), PHP_INT_MAX);
        Assert::same(Domain::plus(PHP_INT_MAX, 1), PHP_INT_MAX);
        Assert::same(Domain::plus(PHP_INT_MAX - 1, 1), PHP_INT_MAX);
        Assert::same(Domain::plus(3, 4), 7);
    }

    public function cartesianWalksTheFirstKeySlowestAndKeepsKeys(): void
    {
        $walk = [];

        foreach (Domain::cartesian(['a' => new BoolArbitrary(), 'b' => new IntArbitrary(0, 2)]) as $tuple) {
            Assert::same(array_keys($tuple), ['a', 'b']);
            $walk[] = array_map(static fn(Shrinkable $node): mixed => $node->value, $tuple);
        }

        Assert::same($walk, [
            ['a' => false, 'b' => 0], ['a' => false, 'b' => 1], ['a' => false, 'b' => 2],
            ['a' => true, 'b' => 0], ['a' => true, 'b' => 1], ['a' => true, 'b' => 2],
        ]);
    }

    public function cartesianOfNothingIsOneEmptyTuple(): void
    {
        Assert::same(iterator_to_array(Domain::cartesian([]), preserve_keys: false), [[]]);
    }

    public function cartesianCanBeWalkedTwice(): void
    {
        $components = ['x' => Gen::elements(['p', 'q'])];

        Assert::same(count(iterator_to_array(Domain::cartesian($components), preserve_keys: false)), 2);
        Assert::same(count(iterator_to_array(Domain::cartesian($components), preserve_keys: false)), 2);
    }
}
