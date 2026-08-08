<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates lists of pairwise-distinct elements (strict comparison) drawn from
 * a delegate arbitrary, and shrinks them by length toward the empty array, then
 * element-by-element through each element's own tree — accepting only
 * candidates that keep the list distinct.
 *
 * Generation draws a size, then draws elements, skipping duplicates. Drawing is
 * bounded: after {@see self::MAX_ATTEMPTS_PER_ELEMENT} attempts per requested
 * element the generator settles for the distinct elements found so far — the
 * result may be smaller than the drawn size, mirroring dictOf's key-collision
 * behaviour. An element space too small to reach the minimum size throws
 * {@see GenerationExhausted} rather than hand the property a too-small list.
 *
 * @template TElement
 * @implements ArbitraryInterface<list<TElement>>
 * @api
 */
final readonly class UniqueArrayArbitrary implements ArbitraryInterface
{
    private const int MAX_ATTEMPTS_PER_ELEMENT = 10;

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

        /** @var list<Shrinkable<TElement>> $elements */
        $elements = [];
        /** @var list<mixed> $values */
        $values = [];
        $budget = $size * self::MAX_ATTEMPTS_PER_ELEMENT;

        while (count($elements) < $size && $budget > 0) {
            --$budget;
            $shrinkable = $this->element->generate($random);

            if (in_array($shrinkable->value, $values, strict: true)) {
                continue;
            }

            $elements[] = $shrinkable;
            // Rebuilt via array_map (not appended) to keep the element type
            // knowledge; the list is capped at maxSize, so this stays cheap.
            $values = array_map(static fn(Shrinkable $element): mixed => $element->value, $elements);
        }

        if (count($elements) < $this->minSize) {
            throw new GenerationExhausted(
                'Gen::uniqueArrayOf()',
                $size * self::MAX_ATTEMPTS_PER_ELEMENT,
                sprintf(
                    'only %d distinct value(s) for a minimum size of %d; the element space is too small',
                    count($elements),
                    $this->minSize,
                ),
            );
        }

        return $this->tree($elements);
    }

    /**
     * @param list<Shrinkable<TElement>> $elements
     *
     * @return Shrinkable<list<TElement>>
     */
    private function tree(array $elements): Shrinkable
    {
        $value = array_map(static fn(Shrinkable $element): mixed => $element->value, $elements);

        return Shrinkable::of($value, function () use ($elements, $value): \Generator {
            if ($elements === []) {
                return;
            }

            // 1. Length first: any slice of a distinct list stays distinct.
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

            // 2. Then elements: shrink one element at a time through its own
            //    tree, skipping candidates that would collide with another
            //    element (uniqueness is part of the generated domain).
            foreach ($elements as $index => $element) {
                $others = $value;
                unset($others[$index]);

                foreach ($element->shrinks() as $smaller) {
                    if (in_array($smaller->value, $others, strict: true)) {
                        continue;
                    }

                    yield $this->tree(array_replace($elements, [$index => $smaller]));
                }
            }
        });
    }
}
