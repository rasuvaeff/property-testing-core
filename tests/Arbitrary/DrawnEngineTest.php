<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\DrawnEngine;
use Rasuvaeff\PropertyTesting\Arbitrary\RandomEngineArbitrary;
use Rasuvaeff\PropertyTesting\Internal\DrawContext;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(DrawnEngine::class)]
#[Covers(RandomEngineArbitrary::class)]
final class DrawnEngineTest
{
    #[AfterTest]
    public function disarm(): void
    {
        DrawContext::disarm();
    }

    public function generatesEightBytesPerCallFromTheTape(): void
    {
        DrawContext::arm(new Random(3));
        $engine = new DrawnEngine();

        $first = $engine->generate();
        $second = $engine->generate();
        $recorded = DrawContext::disarm();

        Assert::same(strlen($first), 8);
        Assert::same(strlen($second), 8);
        Assert::same(count($recorded), 2);
        Assert::same($recorded[0]->value, $first);
        Assert::same($recorded[1]->value, $second);
    }

    public function replaysTheTapeSoARandomizerRepeatsItsDecisions(): void
    {
        DrawContext::arm(new Random(5));
        $randomizer = new \Random\Randomizer(new DrawnEngine());
        $decisions = [$randomizer->getInt(0, 1_000_000), $randomizer->getInt(0, 1_000_000), $randomizer->shuffleArray([1, 2, 3, 4, 5])];
        $tape = DrawContext::disarm();

        DrawContext::arm(new Random(99), $tape);
        $replay = new \Random\Randomizer(new DrawnEngine());

        Assert::same([$replay->getInt(0, 1_000_000), $replay->getInt(0, 1_000_000), $replay->shuffleArray([1, 2, 3, 4, 5])], $decisions);
    }

    public function eachDrawShrinksTowardZeroBytes(): void
    {
        DrawContext::arm(new Random(8));
        (new DrawnEngine())->generate();
        [$node] = DrawContext::disarm();

        $smallest = $node;
        foreach ($node->shrinks() as $candidate) {
            $smallest = $candidate;
        }

        Assert::same(strlen($smallest->value), 8);
        Assert::true($smallest->value < $node->value);
    }

    public function throwsOutsideAPropertyRun(): void
    {
        try {
            (new DrawnEngine())->generate();

            Assert::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'Gen::draw() may only be called inside a property run');
        }
    }

    public function theArbitraryIsALeafHoldingAnEngine(): void
    {
        $node = (new RandomEngineArbitrary())->generate(new Random(1));

        Assert::instanceOf($node, Shrinkable::class);
        Assert::instanceOf($node->value, DrawnEngine::class);
        Assert::same(iterator_to_array($node->shrinks(), preserve_keys: false), []);
    }
}
