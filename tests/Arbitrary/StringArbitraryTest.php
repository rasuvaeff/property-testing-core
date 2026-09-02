<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\StringArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(StringArbitrary::class)]
final class StringArbitraryTest
{
    public function generateStaysWithinLengthRange(): void
    {
        $arbitrary = new StringArbitrary(2, 8, unicode: false);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $length = strlen((string) $arbitrary->generate($random)->value);

            Assert::true($length >= 2 && $length <= 8);
        }
    }

    public function shrinkRemovesBlocksOfCharactersLongestFirstFromEveryOffset(): void
    {
        // Whole string, aligned halves, quarters, pairs, single characters:
        // 1 + 2 + 4 + 8 = 15 length candidates for eight characters, each
        // strictly shorter, before any character is touched.
        $node = Trees::generateWhere(
            new StringArbitrary(0, 12),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 8,
        );
        $value = (string) $node->value;
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], '');
        Assert::same($candidates[1], substr($value, 4));
        Assert::same($candidates[2], substr($value, 0, 4));
        Assert::same($candidates[3], substr($value, 2));
        Assert::same($candidates[14], substr($value, 0, 7));

        foreach (array_slice($candidates, 0, 15) as $candidate) {
            Assert::true(is_string($candidate) && strlen($candidate) < 8);
        }
    }

    public function greedyDescentIsolatesAFailingCharacter(): void
    {
        // "No x in the string", failing on "..x": prefix halving keeps the x;
        // single removal reaches "x".
        $node = Trees::generateWhere(
            new StringArbitrary(0, 8),
            static fn(mixed $v): bool => is_string($v) && strlen($v) >= 3 && str_ends_with($v, 'x') && substr_count($v, 'x') === 1,
        );
        $fails = static fn(mixed $v): bool => is_string($v) && str_contains($v, 'x');

        Assert::same(Trees::descendWhile($node, $fails)->value, 'x');
    }

    public function shrinkReducesCharactersTowardLowercaseA(): void
    {
        // After the length candidates, each non-'a' character is driven toward
        // 'a' in place, one position at a time.
        $node = Trees::generateWhere(
            new StringArbitrary(2, 2),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 2 && $v[0] !== 'a' && $v[1] !== 'a',
        );
        $value = $node->value;
        $candidates = Trees::childValues($node);

        Assert::true(in_array('a' . $value[1], $candidates, strict: true));
        Assert::true(in_array($value[0] . 'a', $candidates, strict: true));
    }

    public function shrinkSkipsCharactersAlreadyA(): void
    {
        // A single 'a' is fully shrunk in the character phase: with minLength 1
        // there is no length candidate either, so the node is terminal.
        $node = Trees::generateWhere(
            new StringArbitrary(1, 1),
            static fn(mixed $v): bool => $v === 'a',
        );

        Assert::same(Trees::childValues($node), []);
    }

    public function singleCharacterShrinksOnlyToA(): void
    {
        $node = Trees::generateWhere(
            new StringArbitrary(1, 1),
            static fn(mixed $v): bool => is_string($v) && $v !== 'a',
        );

        Assert::same(Trees::childValues($node), ['a']);
    }

    public function shrinkYieldsTheEmptyStringExactlyOnce(): void
    {
        // The empty string comes only from the minLength===0 guard; the length
        // loop must stop at 1 and never emit a second '' via a zero-length prefix.
        $node = Trees::generateWhere(
            new StringArbitrary(0, 12),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 8,
        );

        $empties = array_filter(Trees::childValues($node), static fn(mixed $candidate): bool => $candidate === '');
        Assert::same(count($empties), 1);
    }

    public function shrinkCharacterPhaseContinuesPastCharactersAlreadyA(): void
    {
        // v[0] is already 'a': the loop must skip it (continue, not break) and
        // still drive v[1] to 'a'. minLength 2 blocks the length phase, so the
        // substitution is the only candidate.
        $node = Trees::generateWhere(
            new StringArbitrary(2, 2),
            static fn(mixed $v): bool => is_string($v) && $v[0] === 'a' && $v[1] !== 'a',
        );

        Assert::same(Trees::childValues($node), ['aa']);
    }

    public function unicodeLengthPhaseHalvesPerCharacterNotPerByte(): void
    {
        // With multibyte codepoints the blocks must be removed per character:
        // a byte-based slice would cut codepoints in half.
        $node = Trees::generateWhere(
            new StringArbitrary(0, 6, unicode: true),
            static fn(mixed $v): bool => is_string($v)
                && mb_strlen($v, 'UTF-8') === 4
                && strlen($v) > 4,
        );
        $value = (string) $node->value;
        $candidates = Trees::childValues($node);

        Assert::same($candidates[0], '');
        Assert::same($candidates[1], mb_substr($value, 2, null, 'UTF-8'));
        Assert::same($candidates[2], mb_substr($value, 0, 2, 'UTF-8'));
        Assert::same($candidates[3], mb_substr($value, 1, null, 'UTF-8'));
        Assert::same($candidates[6], mb_substr($value, 0, 3, 'UTF-8'));

        foreach ($candidates as $candidate) {
            Assert::same(mb_check_encoding((string) $candidate, 'UTF-8'), expected: true);
        }
    }

    public function shrinkNeverEscapesBelowMinimumLength(): void
    {
        // stringOf(5, 10)-style generator must never shrink to '' (out of domain).
        $node = Trees::generateWhere(
            new StringArbitrary(5, 10),
            static fn(mixed $v): bool => is_string($v) && mb_strlen($v, 'UTF-8') >= 8,
        );

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::true(mb_strlen((string) $candidate, 'UTF-8') >= 5);
        }
    }

    public function shrinkKeepsTheMinimumLengthCandidate(): void
    {
        // With minLength 2 the length floor (exactly 2 chars) is reachable by
        // descent, and the descent never goes below it.
        $node = Trees::generateWhere(
            new StringArbitrary(2, 100),
            static fn(mixed $v): bool => is_string($v) && strlen($v) === 8,
        );

        $floor = Trees::descendWhile($node, static fn(mixed $v): bool => is_string($v) && strlen($v) >= 2)->value;

        Assert::true(is_string($floor) && strlen($floor) === 2);
    }

    public function shrinkOfEmptyStringYieldsNothing(): void
    {
        $node = Trees::generateWhere(new StringArbitrary(0, 3), static fn(mixed $v): bool => $v === '');

        Assert::same(Trees::childValues($node), []);
    }

    public function unicodeGenerationProducesValidUtf8(): void
    {
        // 3000 seeds make a surrogate draw (p ~ 0.18% per codepoint) all but
        // certain, so a broken surrogate-skip loop cannot slip through as the
        // empty-string fallback.
        $arbitrary = new StringArbitrary(1, 1, unicode: true);

        for ($seed = 1; $seed <= 3000; ++$seed) {
            $value = $arbitrary->generate(new Random($seed))->value;

            Assert::same(mb_check_encoding($value, 'UTF-8'), expected: true);
            // A single requested character must yield exactly one codepoint (never
            // the empty fallback), and it must be valid UTF-8.
            Assert::same(mb_strlen((string) $value, 'UTF-8'), 1);
        }
    }

    public function shrinkHalvesAndDrivesCharactersByCharacterNotByte(): void
    {
        // Multibyte strings must halve and substitute per character, never per
        // byte, so candidates stay valid UTF-8 of the right character length.
        $node = Trees::generateWhere(
            new StringArbitrary(4, 4, unicode: true),
            static fn(mixed $v): bool => is_string($v)
                && mb_strlen($v, 'UTF-8') === 4
                && strlen($v) > 4
                && !str_contains($v, 'a'),
        );
        $value = $node->value;
        $candidates = Trees::childValues($node);

        // minLength 4 blocks the length phase entirely: only the four character
        // substitutions remain, each replacing one codepoint with 'a'.
        Assert::same(count($candidates), 4);
        $chars = mb_str_split((string) $value, 1, 'UTF-8');
        Assert::same($candidates[0], 'a' . implode('', array_slice($chars, 1)));

        foreach ($candidates as $candidate) {
            Assert::same(mb_check_encoding($candidate, 'UTF-8'), expected: true);
            Assert::same(mb_strlen((string) $candidate, 'UTF-8'), 4);
        }
    }

    public function generateReachesBothExactLengthBounds(): void
    {
        $arbitrary = new StringArbitrary(1, 4, unicode: false);
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

    public function unicodeGenerationIsCodepointExactForAFixedSeed(): void
    {
        // Pins the category roll and every range bound: a shifted weight or a
        // moved bound relabels the draws and every generated character moves.
        $value = (new StringArbitrary(5, 5, unicode: true))->generate(new Random(1))->value;

        Assert::same($value, "\u{F3A65}\u{77F00}I[d");
    }

    public function aLongUnicodeStringIsByteExactForAFixedSeed(): void
    {
        // 120 characters visit every branch of the category roll several
        // times: any weight or bound moved by one changes some character.
        $value = (new StringArbitrary(120, 120, unicode: true))->generate(new Random(2024))->value;

        Assert::same(
            bin2hex($value),
            '514b2f78f3af859a26f0a3adbef2bfab8821635df281a9b2265e61f289adbbe783a6c3b56ccc81e8a086e3ad957fe8ab863d2cf190b786c8b8c98f2ef39487963372c59220e1b1a776e9889b36c8b8e99f985b242827f3908392f38cafbd6c736369e2bc82432c3773f09f9880e2808b5354eb8abb393c7556c3a40d315178f485b2bf222e316d68c6baf1a18690c6aae280ae43c598c39b2073223e7e5ae5afa4e8a5afc48c3e7368c68be78cb6637cf3aaa2947e79e59cb17a0953e4b684c5a6f1bab18ec7b631e3bd98f0aba3967e27c3bcf48396a3f298a6ba27f3b186a5',
        );
    }

    public function theBmpBranchRedrawsBothSurrogateEndpoints(): void
    {
        // Found by replaying the Randomizer (length draw, category roll, then
        // the codepoint): seed 81904 rolls the BMP branch and draws exactly
        // U+DFFF first; seed 946435 draws exactly U+D800. Both must be redrawn
        // — accepting either makes mb_chr() fail and collapses the character
        // to ''.
        Assert::same((new StringArbitrary(1, 1, unicode: true))->generate(new Random(81904))->value, "\u{F5CD}");
        Assert::same((new StringArbitrary(1, 1, unicode: true))->generate(new Random(946435))->value, "\u{2B35}");
    }

    public function unicodeNeverProducesASurrogateOrAnUnencodableCharacter(): void
    {
        // The BMP and full-range branches redraw surrogates, which mb_chr()
        // cannot encode and which would collapse the character to ''.
        $arbitrary = new StringArbitrary(1, 1, unicode: true);
        $random = new Random(9);

        for ($i = 0; $i < 5_000; ++$i) {
            $char = $arbitrary->generate($random)->value;

            Assert::true($char !== '' && mb_check_encoding($char, 'UTF-8'));
            $codepoint = mb_ord($char, 'UTF-8');
            Assert::true($codepoint >= 1 && $codepoint <= 0x10FFFF);
            Assert::false($codepoint >= 0xD800 && $codepoint <= 0xDFFF);
        }
    }

    public function unicodeDrawsAreReadableAndAdversarialAtOnce(): void
    {
        // Half ASCII so the strings stay readable; troublemakers, Latin,
        // the rest of the BMP and the astral planes all present — the
        // characters parsers trip over are drawn, not hoped for.
        $arbitrary = new StringArbitrary(1, 1, unicode: true);
        $random = new Random(7);
        $ascii = 0;
        $latin = 0;
        $bmp = 0;
        $astral = 0;
        $seen = [];

        for ($i = 0; $i < 3_000; ++$i) {
            $codepoint = mb_ord($arbitrary->generate($random)->value, 'UTF-8');
            $seen[$codepoint] = true;
            $ascii += $codepoint < 0x80 ? 1 : 0;
            $latin += $codepoint >= 0xA1 && $codepoint <= 0x24F ? 1 : 0;
            $bmp += $codepoint >= 0x250 && $codepoint <= 0xFFFF ? 1 : 0;
            $astral += $codepoint > 0xFFFF ? 1 : 0;
        }

        Assert::true($ascii > 1_200 && $ascii < 1_900);
        Assert::true($latin > 150 && $bmp > 200 && $astral > 400);

        foreach ([0x22, 0x27, 0x5C, 0x200D, 0x202E, 0xFEFF, 0x1F600] as $troublemaker) {
            Assert::true(isset($seen[$troublemaker]));
        }
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeMinimumLength(): void
    {
        new StringArbitrary(-1, 5);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsZeroMaximumLength(): void
    {
        new StringArbitrary(0, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvertedLength(): void
    {
        new StringArbitrary(10, 2);
    }
}
