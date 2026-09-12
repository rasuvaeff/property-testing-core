<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\Arbitrary\DateTimeArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DateTimeArbitrary::class)]
final class DateTimeArbitraryTest
{
    public function generateStaysWithinTheConfiguredRange(): void
    {
        $min = new DateTimeImmutable('@1000');
        $max = new DateTimeImmutable('@2000');
        $arbitrary = new DateTimeArbitrary($min, $max);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $timestamp = $arbitrary->generate($random)->value->getTimestamp();

            Assert::true($timestamp >= 1000 && $timestamp <= 2000);
        }
    }

    public function generateReachesBothExactBounds(): void
    {
        $arbitrary = new DateTimeArbitrary(new DateTimeImmutable('@10'), new DateTimeImmutable('@13'));
        $random = new Random(1);
        $min = PHP_INT_MAX;
        $max = PHP_INT_MIN;

        for ($i = 0; $i < 200; ++$i) {
            $timestamp = $arbitrary->generate($random)->value->getTimestamp();
            $min = min($min, $timestamp);
            $max = max($max, $timestamp);
        }

        Assert::same($min, 10);
        Assert::same($max, 13);
    }

    public function shrinkMovesTowardTheEpochThroughAnIntegerLadder(): void
    {
        // The epoch first, then the bisections of an int shrink — so "any date
        // after 2000 fails" minimises to the boundary instead of stopping at
        // the original because the one candidate passed.
        $node = Trees::generateWhere(
            new DateTimeArbitrary(),
            static fn(mixed $v): bool => $v instanceof DateTimeImmutable && $v->getTimestamp() > 1_000,
        );
        $candidates = Trees::childValues($node);

        Assert::true(count($candidates) > 1);
        Assert::same($candidates[0]->getTimestamp(), 0);

        $minimal = Trees::descendWhile(
            $node,
            static fn(mixed $v): bool => $v instanceof DateTimeImmutable && $v >= new DateTimeImmutable('2000-01-01T00:00:00Z'),
        );

        Assert::same($minimal->value->format('Y-m-d\TH:i:s.uP'), '2000-01-01T00:00:00.000000+00:00');
    }

    public function shrinkClampsTheEpochIntoTheRange(): void
    {
        // A range that excludes the epoch shrinks toward the nearest bound.
        $node = Trees::generateWhere(
            new DateTimeArbitrary(new DateTimeImmutable('@1000'), new DateTimeImmutable('@2000')),
            static fn(mixed $v): bool => $v instanceof DateTimeImmutable && $v->getTimestamp() !== 1000,
        );

        Assert::same(Trees::childValues($node)[0]->getTimestamp(), 1000);
    }

    public function shrinkOfTheTargetYieldsNothing(): void
    {
        // A degenerate range pinned at the epoch generates the target itself.
        $arbitrary = new DateTimeArbitrary(new DateTimeImmutable('@0'), new DateTimeImmutable('@0'));

        Assert::same(Trees::childValues($arbitrary->generate(new Random(1))), []);
    }

    public function shrinkTargetsTheEpochWhenItIsInsideTheRange(): void
    {
        // A range spanning the epoch shrinks exactly to timestamp 0.
        $node = Trees::generateWhere(
            new DateTimeArbitrary(new DateTimeImmutable('@-100'), new DateTimeImmutable('@100')),
            static fn(mixed $v): bool => $v instanceof DateTimeImmutable && $v->getTimestamp() !== 0,
        );

        Assert::same(Trees::childValues($node)[0]->getTimestamp(), 0);
    }

    public function theEpochCandidateIsTerminal(): void
    {
        $node = Trees::generateWhere(
            new DateTimeArbitrary(),
            static fn(mixed $v): bool => $v instanceof DateTimeImmutable && $v->getTimestamp() !== 0,
        );

        $epoch = Trees::childValues($node)[0];
        Assert::same($epoch->getTimestamp(), 0);

        foreach ($node->shrinks() as $child) {
            if ($child->value->getTimestamp() === 0) {
                Assert::same(Trees::childValues($child), []);
            }
        }
    }

    public function theDefaultRangeStartsExactlyAtTheEpoch(): void
    {
        // Shrinking a default-range moment all the way down lands on
        // 0.000000: a default minimum one microsecond off would show here.
        $node = Trees::generateWhere(
            new DateTimeArbitrary(),
            static fn(mixed $v): bool => $v instanceof DateTimeImmutable && $v->getTimestamp() > 1_000,
        );

        Assert::same(Trees::descendWhile($node, static fn(mixed $v): bool => true)->value->format('U.u'), '0.000000');
    }

    public function theDefaultRangeNeverProducesAMomentBeforeTheEpoch(): void
    {
        // The boundary bias picks the minimum itself now and then: a default
        // minimum one microsecond below the epoch would surface as a negative
        // moment within a few hundred draws.
        $arbitrary = new DateTimeArbitrary();
        $random = new Random(13);

        for ($i = 0; $i < 500; ++$i) {
            Assert::true($arbitrary->generate($random)->value->format('U.u') >= '0.000000');
            Assert::true($arbitrary->generate($random)->value->getTimestamp() >= 0);
        }
    }

    public function anInvertedRangeIsRefused(): void
    {
        try {
            new DateTimeArbitrary(new DateTimeImmutable('2024-01-01T00:00:00.000001Z'), new DateTimeImmutable('2024-01-01T00:00:00.000000Z'));

            Assert::fail('expected the inverted range to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Min must be less than or equal to max');
        }
    }

    public function boundsKeepTheirMicroseconds(): void
    {
        // min = 12:00:00.5 must never generate 12:00:00.0, and .999999 is a
        // value like any other.
        $min = new DateTimeImmutable('2024-05-01T12:00:00.500000Z');
        $max = new DateTimeImmutable('2024-05-01T12:00:00.999999Z');
        $arbitrary = new DateTimeArbitrary($min, $max);
        $random = new Random(2);
        $sawFraction = false;

        for ($i = 0; $i < 300; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true($value >= $min && $value <= $max);
            Assert::same($value->getTimezone()->getName(), 'UTC');
            $sawFraction = $sawFraction || (int) $value->format('u') !== 0;
        }

        Assert::true($sawFraction);
    }

    public function aNegativeMomentWithAFractionIsBuiltCorrectly(): void
    {
        // intdiv truncates toward zero: -0.5 s is second -1 plus 500000 µs.
        $min = new DateTimeImmutable('1969-12-31T23:59:59.500000Z');
        $max = new DateTimeImmutable('1969-12-31T23:59:59.750000Z');
        $arbitrary = new DateTimeArbitrary($min, $max);
        $random = new Random(5);

        for ($i = 0; $i < 100; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true($value >= $min && $value <= $max);
            Assert::same($value->format('Y-m-d\TH:i:s'), '1969-12-31T23:59:59');
        }
    }

    public function defaultRangeDrawIsExactForAGivenSeed(): void
    {
        // Pins the default bounds (epoch .. 2100-01-01) byte-exactly: shifting
        // either default by one microsecond changes the draw for this seed.
        Assert::same((new DateTimeArbitrary())->generate(new Random(1))->value->format('U.u'), '4102444800.000000');
        Assert::same((new DateTimeArbitrary())->generate(new Random(3))->value->format('U.u'), (new DateTimeArbitrary())->generate(new Random(3))->value->format('U.u'));
    }

    public function acceptsADegenerateRange(): void
    {
        // min === max is a valid single-point range and must construct + generate.
        $arbitrary = new DateTimeArbitrary(new DateTimeImmutable('@5'), new DateTimeImmutable('@5'));

        Assert::same($arbitrary->generate(new Random(1))->value->getTimestamp(), 5);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvertedRange(): void
    {
        new DateTimeArbitrary(new DateTimeImmutable('@2000'), new DateTimeImmutable('@1000'));
    }

    /**
     * A DateTimeImmutable holds a 64-bit second timestamp, so a bound some
     * 292 000 years out is a valid moment whose microsecond count is not a
     * valid integer. The last representable microsecond on either side still
     * constructs and generates; one past it is refused before the arithmetic
     * can overflow into a float and a TypeError.
     */
    public function acceptsTheLastMicrosecondA64BitIntegerHolds(): void
    {
        $min = DateTimeImmutable::createFromFormat('U.u', '-9223372036854.000000');
        $max = DateTimeImmutable::createFromFormat('U.u', '9223372036854.775807');
        \assert($min instanceof DateTimeImmutable && $max instanceof DateTimeImmutable);

        $arbitrary = new DateTimeArbitrary($min, $min);
        Assert::same($arbitrary->generate(new Random(1))->value->format('U.u'), '-9223372036854.000000');

        $arbitrary = new DateTimeArbitrary($max, $max);
        Assert::same($arbitrary->generate(new Random(1))->value->format('U.u'), '9223372036854.775807');
    }

    #[DataProvider('unrepresentableBoundProvider')]
    public function rejectsABoundPastTheMicrosecondRange(string $bound, bool $asMin): void
    {
        $moment = DateTimeImmutable::createFromFormat('U.u', $bound);
        \assert($moment instanceof DateTimeImmutable);

        try {
            $asMin ? new DateTimeArbitrary($moment) : new DateTimeArbitrary(null, $moment);

            Assert::fail('expected the bound to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('lies outside the microsecond range of a 64-bit integer');
        }
    }

    public static function unrepresentableBoundProvider(): iterable
    {
        yield 'one second before the negative limit' => ['-9223372036855.000000', true];
        yield 'one microsecond past the positive limit' => ['9223372036854.775808', false];
        yield 'one second past the positive limit' => ['9223372036855.000000', false];
        yield 'far past, as min' => ['9223372036855.000000', true];
    }
}
