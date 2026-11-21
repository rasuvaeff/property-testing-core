<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Internal\BlockRemovals;
use Rasuvaeff\PropertyTesting\Internal\Bounds;
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
            throw new \InvalidArgumentException('Gen::stringFrom(): alphabet must not be empty');
        }
        Bounds::size('Gen::stringFrom()', 'minLength', 'maxLength', $minLength, $maxLength);

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

            // 1. Length first: remove blocks of characters (all, halves, …, single
            //    characters) from every offset, counted in characters so multibyte
            //    alphabets never split mid-codepoint. Never shrink below minLength.
            $chars = mb_str_split($value, 1, 'UTF-8');

            foreach (BlockRemovals::of(count($chars), $this->minLength) as [$offset, $length]) {
                yield $this->tree(implode('', [...array_slice($chars, 0, $offset), ...array_slice($chars, $offset + $length)]));
            }

            // 2. Then characters: drive each character toward the first alphabet
            //    character, one position at a time. Each candidate has one fewer
            //    non-canonical character, so this phase terminates.
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
