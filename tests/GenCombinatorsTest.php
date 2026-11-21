<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\ParameterGenerators;
use Rasuvaeff\PropertyTesting\Tests\Support\Priority;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * `anyOf()`, `pair()`, `triple()` and the top-level special classes of
 * `forClass()`: each is the existing generator it stands for, value for value
 * and shrink for shrink under the same seed.
 */
#[Test]
#[Covers(Gen::class)]
#[Covers(ParameterGenerators::class)]
final class GenCombinatorsTest
{
    public function anyOfIsFrequencyWithEqualWeights(): void
    {
        $anyOf = Gen::anyOf(Gen::intBetween(0, 9), Gen::constant('x'), Gen::bool());
        $frequency = Gen::frequency([[1, Gen::intBetween(0, 9)], [1, Gen::constant('x')], [1, Gen::bool()]]);

        Assert::same(Gen::sample($anyOf, 200, 5), Gen::sample($frequency, 200, 5));
        Assert::same(Gen::sampleShrinks($anyOf, 5, 50), Gen::sampleShrinks($frequency, 5, 50));
    }

    public function anyOfReachesEveryGenerator(): void
    {
        $seen = array_unique(array_map(get_debug_type(...), Gen::sample(Gen::anyOf(Gen::int(), Gen::constant(null)), 100, 3)));
        sort($seen);

        Assert::same($seen, ['int', 'null']);
    }

    public function anyOfOfOneIsThatGenerator(): void
    {
        Assert::same(Gen::sample(Gen::anyOf(Gen::intBetween(1, 3)), 20, 1), Gen::sample(Gen::frequency([[1, Gen::intBetween(1, 3)]]), 20, 1));
    }

    public function anyOfOfNothingIsRefused(): void
    {
        try {
            Gen::anyOf();

            Assert::fail('expected an empty choice to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Gen::anyOf() requires at least one generator');
        }
    }

    public function anyOfTakesNamedGeneratorsInOrder(): void
    {
        $named = Gen::anyOf(...['a' => Gen::constant(1), 'b' => Gen::constant(2)]);

        Assert::same(Gen::sample($named, 50, 9), Gen::sample(Gen::frequency([[1, Gen::constant(1)], [1, Gen::constant(2)]]), 50, 9));
    }

    public function pairIsTheTwoElementTuple(): void
    {
        $pair = Gen::pair(Gen::intBetween(0, 100), Gen::stringAscii());
        $tuple = Gen::tuple(Gen::intBetween(0, 100), Gen::stringAscii());

        Assert::same(Gen::sample($pair, 50, 4), Gen::sample($tuple, 50, 4));
        Assert::same(Gen::sampleShrinks($pair, 4, 30), Gen::sampleShrinks($tuple, 4, 30));
    }

    public function tripleIsTheThreeElementTuple(): void
    {
        $triple = Gen::triple(Gen::intBetween(0, 100), Gen::bool(), Gen::nullable(Gen::int()));
        $tuple = Gen::tuple(Gen::intBetween(0, 100), Gen::bool(), Gen::nullable(Gen::int()));

        Assert::same(Gen::sample($triple, 50, 6), Gen::sample($tuple, 50, 6));
        Assert::same(Gen::sampleShrinks($triple, 6, 30), Gen::sampleShrinks($tuple, 6, 30));
    }

    public function forClassOfAnEnumIsItsCases(): void
    {
        Assert::same(Gen::sample(Gen::forClass(Priority::class), 30, 2), Gen::sample(Gen::enum(Priority::class), 30, 2));
    }

    public function forClassOfDateTimeImmutableIsDatetime(): void
    {
        Assert::same(
            array_map(static fn(\DateTimeImmutable $d): string => $d->format('U.u'), Gen::sample(Gen::forClass(\DateTimeImmutable::class), 30, 2)),
            array_map(static fn(\DateTimeImmutable $d): string => $d->format('U.u'), Gen::sample(Gen::datetime(), 30, 2)),
        );
    }

    public function forClassOfTheRandomApiIsItsGenerator(): void
    {
        Assert::instanceOf(Gen::sample(Gen::forClass(\Random\Engine::class), 1)[0], \Random\Engine::class);
        Assert::instanceOf(Gen::sample(Gen::forClass(\Random\Randomizer::class), 1)[0], \Random\Randomizer::class);
    }

    public function forClassOfAnotherDateTimeIsRefusedByName(): void
    {
        try {
            Gen::forClass(\DateTime::class);

            Assert::fail('expected DateTime to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Cannot generate DateTime: only DateTimeImmutable itself is generated from a signature; pass an override');
        }
    }

    /**
     * Overrides ask for the constructor: they reach it as before.
     */
    public function forClassWithOverridesStillBuildsThroughTheConstructor(): void
    {
        $generator = Gen::forClass(\DateTimeImmutable::class, ['datetime' => Gen::constant('2024-02-03 04:05:06'), 'timezone' => Gen::constant(null)]);

        Assert::same(Gen::sample($generator, 1)[0]->format('Y-m-d H:i:s'), '2024-02-03 04:05:06');
    }
}
