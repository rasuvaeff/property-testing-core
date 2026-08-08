<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
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
     * @param bool $unicode When true, characters are drawn from the full Unicode codepoint space (U+0001..U+10FFFF, excluding surrogates); otherwise ASCII printable.
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

            // 1. Length first: empty string, then halves of the original. Counted in
            //    characters (not bytes) so multibyte strings never split mid-codepoint.
            //    Never shrink below minLength, so the candidate stays in the generated
            //    domain (e.g. stringOf(5, 10) never shrinks to '').
            if ($this->minLength === 0) {
                yield $this->tree('');
            }

            $length = mb_strlen($value, 'UTF-8');
            while ($length > 1) {
                $length = intdiv($length, 2);

                if ($length >= $this->minLength) {
                    yield $this->tree(mb_substr($value, 0, $length, 'UTF-8'));
                }
            }

            // 2. Then characters: drive each character toward 'a', the canonical
            //    simplest character, one position at a time. Each candidate has one
            //    fewer non-'a' character, so this phase also terminates.
            $chars = mb_str_split($value, 1, 'UTF-8');
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
        do {
            $codepoint = $random->int(1, 0x10FFFF);
        } while ($codepoint >= 0xD800 && $codepoint <= 0xDFFF);

        $char = mb_chr($codepoint, 'UTF-8');

        return $char === false ? '' : $char;
    }
}
