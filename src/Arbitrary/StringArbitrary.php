<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Internal\BlockRemovals;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates random strings and shrinks them by length toward the empty string,
 * then character-by-character toward 'a'.
 *
 * Two alphabets are available: an ASCII printable subset (32..126) and the
 * full Unicode space via {@see mb_chr()}. Length is chosen uniformly within an
 * inclusive range.
 *
 * @implements ArbitraryInterface<string>
 * @api
 */
final readonly class StringArbitrary implements ArbitraryInterface
{
    private const int ASCII_MIN = 32;
    private const int ASCII_MAX = 126;

    /**
     * Characters parsers and renderers trip over, drawn deliberately rather
     * than hoped for: a uniform draw over 1.1 million codepoints meets a
     * quote once in a million characters.
     *
     * @var non-empty-list<int>
     */
    private const array TROUBLEMAKERS = [
        0x0020, // space
        0x0022, // double quote
        0x0027, // single quote
        0x005C, // backslash
        0x003C, // <
        0x003E, // >
        0x0026, // &
        0x0009, // tab
        0x000A, // line feed
        0x000D, // carriage return
        0x007F, // delete
        0x00A0, // no-break space
        0x00AD, // soft hyphen
        0x0301, // combining acute accent
        0x0308, // combining diaeresis
        0x200B, // zero-width space
        0x200D, // zero-width joiner
        0x200F, // right-to-left mark
        0x202E, // right-to-left override
        0x2028, // line separator
        0xFEFF, // byte order mark
        0xFFFD, // replacement character
        0x1F600, // emoji (astral, four UTF-8 bytes)
        0x1F468, // emoji base with ZWJ sequences
        0xE0041, // tag character (astral, invisible)
    ];

    /**
     * @param bool $unicode When true, characters are drawn from the whole Unicode codepoint space
     *        with a distribution that keeps the string readable and adversarial at once: half
     *        the characters are ASCII printable, a tenth come from a list of troublemakers
     *        (quotes, backslash, combining marks, zero-width joiner, right-to-left override,
     *        byte order mark, astral emoji, …), a tenth from the Latin-1/Latin Extended block,
     *        a tenth from the rest of the Basic Multilingual Plane, and a fifth uniformly from
     *        U+0001..U+10FFFF (surrogates excluded). Otherwise ASCII printable only.
     */
    public function __construct(
        private int $minLength = 0,
        private int $maxLength = 100,
        private bool $unicode = false,
    ) {
        if ($minLength < 0) {
            throw new \InvalidArgumentException('Minimum length must be greater than or equal to 0');
        }
        if ($maxLength < 1) {
            throw new \InvalidArgumentException('Maximum length must be greater than or equal to 1');
        }
        if ($minLength > $maxLength) {
            throw new \InvalidArgumentException('Minimum length must be less than or equal to maximum length');
        }
    }

    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $length = $random->int($this->minLength, $this->maxLength);
        $string = '';

        for ($i = 0; $i < $length; ++$i) {
            $string .= $this->unicode
                ? $this->unicodeChar($random)
                : chr($random->int(self::ASCII_MIN, self::ASCII_MAX));
        }

        return $this->tree($string);
    }

    /** @return Shrinkable<string> */
    private function tree(string $value): Shrinkable
    {
        return Shrinkable::of($value, function () use ($value): \Generator {
            if ($value === '') {
                return;
            }

            // 1. Length first: remove blocks of characters (all, halves, …, single
            //    characters) from every offset. Counted in characters (not bytes) so
            //    multibyte strings never split mid-codepoint. Never shrink below
            //    minLength, so the candidate stays in the generated domain (e.g.
            //    stringOf(5, 10) never shrinks to '').
            $chars = mb_str_split($value, 1, 'UTF-8');

            foreach (BlockRemovals::of(count($chars), $this->minLength) as [$offset, $length]) {
                yield $this->tree(implode('', [...array_slice($chars, 0, $offset), ...array_slice($chars, $offset + $length)]));
            }

            // 2. Then characters: drive each character toward 'a', the canonical
            //    simplest character, one position at a time. Each candidate has one
            //    fewer non-'a' character, so this phase also terminates.
            foreach ($chars as $index => $char) {
                if ($char === 'a') {
                    continue;
                }

                $candidate = $chars;
                $candidate[$index] = 'a';

                yield $this->tree(implode('', $candidate));
            }
        });
    }

    /**
     * Draw a single Unicode codepoint, skipping surrogates (U+D800..U+DFFF) which
     * mb_chr() cannot encode. Returns an empty string if encoding fails.
     */
    private function unicodeChar(Random $random): string
    {
        $codepoint = match ($random->int(1, 10)) {
            1, 2, 3, 4, 5 => $random->int(self::ASCII_MIN, self::ASCII_MAX),
            6 => self::TROUBLEMAKERS[$random->int(0, count(self::TROUBLEMAKERS) - 1)],
            7 => $random->int(0x00A1, 0x024F),
            8 => $this->outsideSurrogates($random, 0x0250, 0xFFFF),
            default => $this->outsideSurrogates($random, 1, 0x10FFFF),
        };

        $char = mb_chr($codepoint, 'UTF-8');

        return $char === false ? '' : $char;
    }

    /**
     * A codepoint in `[$min, $max]` that is not a surrogate (U+D800..U+DFFF),
     * which mb_chr() cannot encode.
     */
    private function outsideSurrogates(Random $random, int $min, int $max): int
    {
        do {
            $codepoint = $random->int($min, $max);
        } while ($codepoint >= 0xD800 && $codepoint <= 0xDFFF);

        return $codepoint;
    }
}
