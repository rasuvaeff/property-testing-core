<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates strings whose characters come from a fixed alphabet, and shrinks
 * them by length toward the empty string, then character-by-character toward
 * the first alphabet character (list simpler characters first).
 *
 * The alphabet is split per Unicode codepoint, so multibyte alphabets work;
 * duplicate characters are collapsed. Length is chosen uniformly within an
 * inclusive range.
 *
 * @implements ArbitraryInterface<string>
 * @api
 */
final readonly class CharsetStringArbitrary implements ArbitraryInterface
{
    /** @var non-empty-list<string> */
    private array $chars;

    public function __construct(
        string $alphabet,
        private int $minLength = 0,
        private int $maxLength = 100,
    ) {
        if ($alphabet === '') {
            throw new \InvalidArgumentException('Alphabet must not be empty');
        }
        if ($minLength < 0) {
            throw new \InvalidArgumentException('Minimum length must be greater than or equal to 0');
        }
        if ($maxLength < 1) {
            throw new \InvalidArgumentException('Maximum length must be greater than or equal to 1');
        }
        if ($minLength > $maxLength) {
            throw new \InvalidArgumentException('Minimum length must be less than or equal to maximum length');
        }

        $chars = array_values(array_unique(mb_str_split($alphabet, 1, 'UTF-8')));

        // A non-empty alphabet always leaves at least one character after dedupe.
        \assert($chars !== []);

        $this->chars = $chars;
    }

    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $length = $random->int($this->minLength, $this->maxLength);
        $string = '';

        for ($i = 0; $i < $length; ++$i) {
            $string .= $this->chars[$random->int(0, count($this->chars) - 1)];
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

            // 1. Length first: empty string, then halves of the original, counted
            //    in characters so multibyte alphabets never split mid-codepoint.
            //    Never shrink below minLength.
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

            // 2. Then characters: drive each character toward the first alphabet
            //    character, one position at a time. Each candidate has one fewer
            //    non-canonical character, so this phase terminates.
            $chars = mb_str_split($value, 1, 'UTF-8');
            foreach ($chars as $index => $char) {
                if ($char === $this->chars[0]) {
                    continue;
                }

                $candidate = $chars;
                $candidate[$index] = $this->chars[0];

                yield $this->tree(implode('', $candidate));
            }
        });
    }
}
