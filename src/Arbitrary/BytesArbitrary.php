<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Internal\BlockRemovals;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates raw byte strings (every byte 0..255, not printable text) and
 * shrinks them by length toward the empty string, then byte-by-byte toward
 * NUL ("\x00"). Useful for parsers, codecs and binary protocols.
 *
 * @implements ArbitraryInterface<string>
 * @api
 */
final readonly class BytesArbitrary implements ArbitraryInterface
{
    public function __construct(
        private int $minLength = 0,
        private int $maxLength = 100,
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
        return $this->tree($random->bytes($random->int($this->minLength, $this->maxLength)));
    }

    /** @return Shrinkable<string> */
    private function tree(string $value): Shrinkable
    {
        return Shrinkable::of($value, function () use ($value): \Generator {
            if ($value === '') {
                return;
            }

            // 1. Length first: remove blocks of bytes (all, halves, …, single
            //    bytes) from every offset. Never shrink below minLength.
            foreach (BlockRemovals::of(strlen($value), $this->minLength) as [$offset, $length]) {
                yield $this->tree(substr($value, 0, $offset) . substr($value, $offset + $length));
            }

            // 2. Then bytes: drive each byte toward "\x00", the canonical
            //    simplest byte, one position at a time.
            for ($index = 0, $bytes = strlen($value); $index < $bytes; ++$index) {
                if ($value[$index] === "\x00") {
                    continue;
                }

                $candidate = $value;
                $candidate[$index] = "\x00";

                yield $this->tree($candidate);
            }
        });
    }
}
