<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates lists whose elements come from a delegate arbitrary and shrinks
 * them by length toward the empty array, then element-by-element through each
 * element's own shrink tree.
 *
 * Element shrink trees are captured at generation time, so elements produced
 * by transformed arbitraries ({@see \Rasuvaeff\PropertyTesting\Gen::map()},
 * {@see \Rasuvaeff\PropertyTesting\Gen::flatMap()}) shrink correctly.
 *
 * @template TElement
 * @implements ArbitraryInterface<list<TElement>>
 * @api
 */
final readonly class ArrayArbitrary implements ArbitraryInterface
{
    /**
     * @param ArbitraryInterface<TElement> $element
     */
    public function __construct(
        private ArbitraryInterface $element,
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
     * @return Shrinkable<list<TElement>>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $size = $random->int($this->minSize, $this->maxSize);

        // Guard size 0 explicitly: range(1, 0) === [1, 0], so a naive range() would
        // yield two elements and an empty list would never be generated.
        return $this->tree($size === 0 ? [] : array_map(
            fn(int $i): Shrinkable => $this->element->generate($random),
            range(1, $size),
        ));
    }

    /**
     * @param list<Shrinkable<TElement>> $elements
     *
     * @return Shrinkable<list<TElement>>
     */
    private function tree(array $elements): Shrinkable
    {
        $value = array_map(static fn(Shrinkable $element): mixed => $element->value, $elements);

        return Shrinkable::of($value, function () use ($elements): \Generator {
            if ($elements === []) {
                return;
            }

            // 1. Length first: empty array, then progressively shorter halves of the
            //    original. Dropping elements is the most aggressive simplification.
            //    Never shrink below minSize, so the candidate stays in the generated
            //    domain (e.g. a nonEmptyArrayOf never shrinks to []).
            if ($this->minSize === 0) {
                yield $this->tree([]);
            }

            $length = count($elements);
            while ($length > 1) {
                $length = intdiv($length, 2);

                if ($length >= $this->minSize) {
                    yield $this->tree(array_slice($elements, 0, $length));
                }
            }

            // 2. Then elements: shrink one element at a time through its own tree,
            //    keeping the array length fixed.
            foreach ($elements as $index => $element) {
                foreach ($element->shrinks() as $smaller) {
                    yield $this->tree(array_replace($elements, [$index => $smaller]));
                }
            }
        });
    }
}
