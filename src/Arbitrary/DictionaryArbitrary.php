<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates associative arrays (maps) whose keys come from a key arbitrary and
 * whose values come from a value arbitrary, then shrinks them by size toward the
 * empty map and value-by-value through each value's own shrink tree (keys are
 * never shrunk).
 *
 * Keys must be PHP array keys (int or string); a key arbitrary that produces
 * anything else is a configuration error and throws. Generation draws a size,
 * then draws distinct keys (each paired with a value) up to an attempt budget,
 * so seeded runs are reproducible. When the key space runs out of fresh keys the
 * map may be smaller than the drawn size, but it is NEVER smaller than
 * {@see $minSize}: an unreachable minimum throws {@see GenerationExhausted}
 * rather than hand the property a too-small map.
 *
 * @template TKey of array-key
 * @template TValue
 * @implements ArbitraryInterface<array<TKey, TValue>>
 * @api
 */
final readonly class DictionaryArbitrary implements ArbitraryInterface
{
    /**
     * Draws per requested key before giving up (guards a key space too small to
     * fill the drawn size with distinct keys).
     */
    private const int MAX_ATTEMPTS_PER_KEY = 10;

    /**
     * @param ArbitraryInterface<TKey>   $key
     * @param ArbitraryInterface<TValue> $value
     */
    public function __construct(
        private ArbitraryInterface $key,
        private ArbitraryInterface $value,
        private int $minSize = 0,
        private int $maxSize = 100,
    ) {
        if ($minSize < 0) {
            throw new \InvalidArgumentException('Minimum size must be greater than or equal to 0');
        }
        if ($maxSize < 1) {
            throw new \InvalidArgumentException('Maximum size must be greater than or equal to 1');
        }
        if ($minSize > $maxSize) {
            throw new \InvalidArgumentException('Minimum size must be less than or equal to maximum size');
        }
    }

    /**
     * @return Shrinkable<array<TKey, TValue>>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $size = $random->int($this->minSize, $this->maxSize);

        if ($size === 0) {
            return $this->tree([]);
        }

        // Draw distinct keys (skipping collisions), each paired with a value, up
        // to a bounded budget so a too-small key space cannot loop forever.
        /** @var array<TKey, Shrinkable<TValue>> $entries */
        $entries = [];
        $budget = $size * self::MAX_ATTEMPTS_PER_KEY;

        while (count($entries) < $size && $budget > 0) {
            --$budget;

            /** @var mixed $key */
            $key = $this->key->generate($random)->value;

            if (!is_int($key) && !is_string($key)) {
                throw new \InvalidArgumentException(sprintf(
                    'Dictionary key arbitrary must produce int or string keys, got %s',
                    get_debug_type($key),
                ));
            }

            if (array_key_exists($key, $entries)) {
                continue;
            }

            $entries[$key] = $this->value->generate($random);
        }

        if (count($entries) < $this->minSize) {
            throw new GenerationExhausted(
                'Gen::dictOf()',
                $size * self::MAX_ATTEMPTS_PER_KEY,
                sprintf(
                    'only %d distinct key(s) for a minimum size of %d; the key arbitrary\'s value space is too small',
                    count($entries),
                    $this->minSize,
                ),
            );
        }

        return $this->tree($entries);
    }

    /**
     * @param array<array-key, Shrinkable<TValue>> $entries
     *
     * @return Shrinkable<array<TKey, TValue>>
     */
    private function tree(array $entries): Shrinkable
    {
        $value = array_map(static fn(Shrinkable $entry): mixed => $entry->value, $entries);

        return Shrinkable::of($value, function () use ($entries): \Generator {
            if ($entries === []) {
                return;
            }

            // 1. Size first: empty map, then progressively smaller halves, preserving
            //    keys so the candidate stays a valid map. Never shrink below minSize,
            //    so the candidate stays within the generated domain.
            if ($this->minSize === 0) {
                yield $this->tree([]);
            }

            $size = count($entries);
            while ($size > 1) {
                $size = intdiv($size, 2);

                if ($size >= $this->minSize) {
                    yield $this->tree(array_slice($entries, 0, $size, preserve_keys: true));
                }
            }

            // 2. Then values: shrink one value at a time through its own tree,
            //    keeping the keys fixed. Keys themselves are not shrunk.
            foreach ($entries as $key => $entry) {
                foreach ($entry->shrinks() as $smaller) {
                    yield $this->tree(array_replace($entries, [$key => $smaller]));
                }
            }
        });
    }
}
