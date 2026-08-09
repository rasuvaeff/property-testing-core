<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\CharsetStringArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CharsetStringArbitrary::class)]
final class CharsetStringArbitraryTest
{
    public function generateDrawsOnlyAlphabetCharactersWithinLengthRange(): void
    {
        $arbitrary = new CharsetStringArbitrary('abc123', 2, 8);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $value = $arbitrary->generate($random)->value;
            $length = strlen((string) $value);

            Assert::true($length >= 2 && $length <= 8);
            Assert::same(preg_match('/^[abc123]+$/', (string) $value), 1);
        }
    }

    public function generateReachesEveryAlphabetCharacter(): void
    {
        $arbitrary = new CharsetStringArbitrary('xyz', 5, 5);
        $random = new Random(1);
        $seen = [];

        for ($i = 0; $i < 100; ++$i) {
            foreach (str_split((string) $arbitrary->generate($random)->value) as $char) {
                $seen[$char] = true;
            }
        }

        Assert::same(isset($seen['x'], $seen['y'], $seen['z']), expected: true);
    }

    public function generateReachesBothExactLengthBounds(): void
    {
        $arbitrary = new CharsetStringArbitrary('ab', 1, 4);
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

    public function supportsMultibyteAlphabets(): void
    {
        $arbitrary = new CharsetStringArbitrary('αβγ', 3, 3);
        $random = new Random(1);

        for ($i = 0; $i < 100; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::same(mb_strlen((string) $value, 'UTF-8'), 3);
            Assert::same(preg_match('/^[αβγ]+$/u', (string) $value), 1);
        }
    }

    public function duplicateAlphabetCharactersAreCollapsed(): void
    {
        // 'aab' collapses to two distinct characters drawn 50/50 (~200 of 400).
        // Without dedupe 'b' would land near 1/3 (~133); the band rules it out.
        $arbitrary = new CharsetStringArbitrary('aab', 1, 1);
        $random = new Random(1);
        $hits = ['a' => 0, 'b' => 0];

        for ($i = 0; $i < 400; ++$i) {
            ++$hits[$arbitrary->generate($random)->value];
        }

        Assert::true($hits['b'] > 165 && $hits['b'] < 235);
    }

    public function multibyteLengthPhaseHalvesPerCharacterNotPerByte(): void
    {
        // Four 2-byte codepoints: the half prefix must be 2 CHARACTERS via
        // mb_substr, and the character phase must substitute whole codepoints.
        $node = Trees::generateWhere(
            new CharsetStringArbitrary('βγδ', 0, 6),
            static fn(mixed $v): bool => is_string($v) && mb_strlen($v, 'UTF-8') === 4,
        );
        $value = $node->value;
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], '');
        Assert::same($candidates[1], mb_substr((string) $value, 0, 2, 'UTF-8'));
        Assert::same($candidates[2], mb_substr((string) $value, 0, 1, 'UTF-8'));

        foreach ($candidates as $candidate) {
            Assert::same(mb_check_encoding($candidate, 'UTF-8'), expected: true);
        }
    }

    public function multibyteCharacterPhaseSubstitutesWholeCodepoints(): void
    {
        // A fixed length blocks the length phase; the first substitution
        // replaces the first codepoint with the canonical 'β'.
        $node = Trees::generateWhere(
            new CharsetStringArbitrary('βγδ', 2, 2),
            static fn(mixed $v): bool => is_string($v) && !str_starts_with($v, 'β'),
        );
        $value = $node->value;

        Assert::same(Trees::childValues($node)[0], 'β' . mb_substr((string) $value, 1, null, 'UTF-8'));
    }

    public function shrinkKeepsTheMinimumLengthCandidate(): void
    {
        // With minLength 2 the length-floor prefix (exactly 2 chars) is produced.
        $node = Trees::generateWhere(
            new CharsetStringArbitrary('bcd', 2, 12),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 8,
        );

        Assert::true(in_array(substr((string) $node->value, 0, 2), Trees::childValues($node), strict: true));
    }

    public function shrinkTriesEmptyStringFirstThenExactHalfPrefixes(): void
    {
        $node = Trees::generateWhere(
            new CharsetStringArbitrary('bcd', 0, 12),
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
            new CharsetStringArbitrary('bcd', 0, 12),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 8,
        );

        $empties = array_filter(Trees::childValues($node), static fn(mixed $candidate): bool => $candidate === '');
        Assert::same(count($empties), 1);
    }

    public function shrinkDrivesCharactersTowardTheFirstAlphabetCharacter(): void
    {
        // Canonical simplest character is the FIRST of the alphabet ('z' here),
        // not the hardcoded 'a' of the generic string arbitrary.
        $node = Trees::generateWhere(
            new CharsetStringArbitrary('zx', 1, 1),
            static fn(mixed $v): bool => $v === 'x',
        );

        Assert::same(Trees::childValues($node), ['z']);
    }

    public function firstAlphabetCharacterIsTerminal(): void
    {
        $node = Trees::generateWhere(
            new CharsetStringArbitrary('zx', 1, 1),
            static fn(mixed $v): bool => $v === 'z',
        );

        Assert::same(Trees::childValues($node), []);
    }

    public function shrinkCharacterPhaseContinuesPastCanonicalCharacters(): void
    {
        // v[0] is already the canonical 'z': the loop must skip it and still
        // drive v[1] toward 'z'.
        $node = Trees::generateWhere(
            new CharsetStringArbitrary('zx', 2, 2),
            static fn(mixed $v): bool => $v === 'zx',
        );

        Assert::same(Trees::childValues($node), ['zz']);
    }

    public function shrinkNeverEscapesBelowMinimumLength(): void
    {
        $node = Trees::generateWhere(
            new CharsetStringArbitrary('bc', 3, 8),
            static fn(mixed $v): bool => is_string($v) && strlen($v) >= 6,
        );

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::true(strlen((string) $candidate) >= 3);
        }
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyAlphabet(): void
    {
        new CharsetStringArbitrary('');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeMinimumLength(): void
    {
        new CharsetStringArbitrary('ab', -1, 5);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsZeroMaximumLength(): void
    {
        new CharsetStringArbitrary('ab', 0, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvertedLength(): void
    {
        new CharsetStringArbitrary('ab', 10, 2);
    }
}
