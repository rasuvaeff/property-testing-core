<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\BytesArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(BytesArbitrary::class)]
final class BytesArbitraryTest
{
    public function generateStaysWithinLengthRange(): void
    {
        $arbitrary = new BytesArbitrary(2, 8);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $length = strlen((string) $arbitrary->generate($random)->value);

            Assert::true($length >= 2 && $length <= 8);
        }
    }

    public function generateProducesNonPrintableBytes(): void
    {
        // Raw bytes span 0..255, unlike the printable string generators.
        $arbitrary = new BytesArbitrary(16, 16);
        $random = new Random(1);
        $sawHighByte = false;

        for ($i = 0; $i < 50 && !$sawHighByte; ++$i) {
            foreach (str_split((string) $arbitrary->generate($random)->value) as $byte) {
                if (ord($byte) > 126) {
                    $sawHighByte = true;

                    break;
                }
            }
        }

        Assert::true($sawHighByte);
    }

    public function generateReachesBothExactLengthBounds(): void
    {
        $arbitrary = new BytesArbitrary(1, 4);
        $random = new Random(1);
        $min = PHP_INT_MAX;
        $max = 0;

        for ($i = 0; $i < 200; ++$i) {
            $length = strlen((string) $arbitrary->generate($random)->value);
            $min = min($min, $length);
            $max = max($max, $length);
        }

        Assert::same($min, 1);
        Assert::same($max, 4);
    }

    public function generatesEmptyStringWhenLengthIsZero(): void
    {
        $arbitrary = new BytesArbitrary(0, 2);
        $random = new Random(1);
        $sawEmpty = false;

        for ($i = 0; $i < 200; ++$i) {
            if ($arbitrary->generate($random)->value === '') {
                $sawEmpty = true;

                break;
            }
        }

        Assert::true($sawEmpty);
    }

    public function shrinkTriesEmptyStringFirstThenExactHalfPrefixes(): void
    {
        $node = Trees::generateWhere(
            new BytesArbitrary(0, 12),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 8,
        );
        $value = $node->value;
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], '');
        Assert::same($candidates[1], substr((string) $value, 0, 4));
        Assert::same($candidates[2], substr((string) $value, 0, 2));
        Assert::same($candidates[3], substr((string) $value, 0, 1));
    }

    public function shrinkYieldsTheEmptyStringExactlyOnce(): void
    {
        $node = Trees::generateWhere(
            new BytesArbitrary(0, 12),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 8,
        );

        $empties = array_filter(Trees::childValues($node), static fn(mixed $candidate): bool => $candidate === '');
        Assert::same(count($empties), 1);
    }

    public function shrinkDrivesBytesTowardNulInPlace(): void
    {
        // A fixed length blocks the length phase: candidates replace one byte
        // at a time with "\x00".
        $node = Trees::generateWhere(
            new BytesArbitrary(2, 2),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 2 && $v[0] !== "\x00" && $v[1] !== "\x00",
        );
        $value = $node->value;

        Assert::same(Trees::childValues($node), ["\x00" . $value[1], $value[0] . "\x00"]);
    }

    public function shrinkBytePhaseSkipsBytesAlreadyNul(): void
    {
        // v[0] is already NUL: the loop must skip it (continue) and still drive
        // v[1] toward NUL.
        $node = Trees::generateWhere(
            new BytesArbitrary(2, 2),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 2 && $v[0] === "\x00" && $v[1] !== "\x00",
        );

        Assert::same(Trees::childValues($node), ["\x00\x00"]);
    }

    public function allNulBytesAreTerminalUpToLengthShrinks(): void
    {
        // "\x00\x00" is essentially never drawn; reach it by descending the
        // byte phase — a fixed length then leaves it with no candidates at all.
        $node = Trees::generateWhere(
            new BytesArbitrary(2, 2),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 2 && $v[0] !== "\x00" && $v[1] !== "\x00",
        );

        $minimal = Trees::descendWhile($node, static fn(mixed $v): bool => true);

        Assert::same($minimal->value, "\x00\x00");
        Assert::same(Trees::childValues($minimal), []);
    }

    public function acceptsMaximumLengthOfOne(): void
    {
        // maxLength === 1 is valid (the boundary of the "at least 1" rule).
        Assert::same(strlen((string) (new BytesArbitrary(1, 1))->generate(new Random(1))->value), 1);
    }

    public function shrinkKeepsTheMinimumLengthCandidate(): void
    {
        // With minLength 2 the length-floor prefix (exactly 2 bytes) is produced.
        $node = Trees::generateWhere(
            new BytesArbitrary(2, 12),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 8,
        );

        Assert::true(in_array(substr((string) $node->value, 0, 2), Trees::childValues($node), strict: true));
    }

    public function shrinkNeverEscapesBelowMinimumLength(): void
    {
        $node = Trees::generateWhere(
            new BytesArbitrary(3, 8),
            static fn(mixed $v): bool => is_string($v) && strlen($v) >= 6,
        );

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::true(strlen((string) $candidate) >= 3);
        }
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeMinimumLength(): void
    {
        new BytesArbitrary(-1, 5);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsZeroMaximumLength(): void
    {
        new BytesArbitrary(0, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvertedLength(): void
    {
        new BytesArbitrary(10, 2);
    }
}
