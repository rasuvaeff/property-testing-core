<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\DrawContext;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DrawContext::class)]
final class DrawContextTest
{
    public function generatesAndRecordsWhenTheTapeIsEmpty(): void
    {
        DrawContext::arm(new Random(1));

        $value = DrawContext::draw(Gen::intBetween(1, 10));
        $recorded = DrawContext::disarm();

        Assert::true($value >= 1 && $value <= 10);
        Assert::same(count($recorded), 1);
        Assert::same($recorded[0]->value, $value);
    }

    public function replaysTheTapeByPosition(): void
    {
        DrawContext::arm(new Random(1));
        DrawContext::draw(Gen::intBetween(1, 1000));
        DrawContext::draw(Gen::intBetween(1, 1000));
        $tape = DrawContext::disarm();

        // A different seed proves the values come from the tape, not the engine.
        DrawContext::arm(new Random(999), $tape);
        $first = DrawContext::draw(Gen::intBetween(1, 1000));
        $second = DrawContext::draw(Gen::intBetween(1, 1000));
        DrawContext::disarm();

        Assert::same($first, $tape[0]->value);
        Assert::same($second, $tape[1]->value);
    }

    public function generatesPastTheEndOfTheTape(): void
    {
        DrawContext::arm(new Random(1));
        DrawContext::draw(Gen::intBetween(1, 10));
        $tape = DrawContext::disarm();

        DrawContext::arm(new Random(2), $tape);
        DrawContext::draw(Gen::intBetween(1, 10));
        $extra = DrawContext::draw(Gen::intBetween(100, 200));
        $recorded = DrawContext::disarm();

        Assert::true($extra >= 100 && $extra <= 200);
        Assert::same(count($recorded), 2);
        Assert::same($recorded[0], $tape[0]);
        Assert::same($recorded[1]->value, $extra);
    }

    #[ExpectException(\RuntimeException::class)]
    public function throwsWhenUnarmed(): void
    {
        DrawContext::draw(Gen::int());
    }

    #[ExpectException(\RuntimeException::class)]
    public function drawAfterDisarmThrows(): void
    {
        DrawContext::arm(new Random(1));
        DrawContext::disarm();

        DrawContext::draw(Gen::int());
    }
}
