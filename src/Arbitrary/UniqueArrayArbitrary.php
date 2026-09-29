<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\Internal\BlockRemovals;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates lists of pairwise-distinct elements drawn from a delegate
 * arbitrary, and shrinks them by length toward the empty array, then
 * element-by-element through each element's own tree — accepting only
 * candidates that keep the list distinct.
 *
 * Distinct means `!==` on the values, or — with a key closure — `===` on the
 * `int|string` key it returns for each value, so a list of value objects can
 * be unique by one field. A key of any other type is refused with
 * {@see \InvalidArgumentException} rather than compared by identity, which
 * would make every element "unique" and hollow the guarantee out silently.
 *
 * Generation draws a size, then draws elements, skipping duplicates. Drawing is
 * bounded: after {@see self::MAX_ATTEMPTS_PER_ELEMENT} attempts per requested
 * element the generator settles for the distinct elements found so far — the
 * result may be smaller than the drawn size, mirroring dictOf's key-collision
 * behaviour. An element space too small to reach the minimum size throws
 * {@see GenerationExhaustedException} rather than hand the property a too-small list.
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
     * @param null|Closure(TElement): mixed $by Expected to return `int|string`; checked at generation time.
     */
    public function __construct(
        private ArbitraryInterface $element,
        private int $minSize = 0,
        private int $maxSize = 100,
        private ?Closure $by = null,
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
        /** @var list<TElement|int|string> $keys */
        $keys = [];
        $budget = $size * self::MAX_ATTEMPTS_PER_ELEMENT;

        while (count($elements) < $size && $budget > 0) {
            --$budget;
            $shrinkable = $this->element->generate($random);

            if (in_array($this->key($shrinkable->value), $keys, strict: true)) {
                continue;
            }

            $elements[] = $shrinkable;
            $keys[] = $this->key($shrinkable->value);
        }

        if (count($elements) < $this->minSize) {
            throw new GenerationExhaustedException(
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
        $keys = array_map(fn(Shrinkable $element): mixed => $this->key($element->value), $elements);

        return Shrinkable::of($value, function () use ($elements, $keys): \Generator {
            if ($elements === []) {
                return;
            }

            // 1. Length first: any subsequence of a distinct list stays distinct,
            //    so blocks can be removed from any offset.
            foreach (BlockRemovals::of(count($elements), $this->minSize) as [$offset, $length]) {
                yield $this->tree([...array_slice($elements, 0, $offset), ...array_slice($elements, $offset + $length)]);
            }

            // 2. Then elements: shrink one element at a time through its own
            //    tree, skipping candidates that would collide with another
            //    element (uniqueness is part of the generated domain).
            foreach ($elements as $index => $element) {
                $others = $keys;
                unset($others[$index]);

                foreach ($element->shrinks() as $smaller) {
                    if (in_array($this->key($smaller->value), $others, strict: true)) {
                        continue;
                    }

                    yield $this->tree(array_replace($elements, [$index => $smaller]));
                }
            }
        });
    }

    /**
     * The identity a value is compared under: the value itself, or what the
     * key closure returns for it.
     *
     * @param TElement $value
     *
     * @return TElement|int|string
     */
    private function key(mixed $value): mixed
    {
        if (!$this->by instanceof Closure) {
            return $value;
        }

        $key = ($this->by)($value);

        if (!is_int($key) && !is_string($key)) {
            throw new \InvalidArgumentException(sprintf(
                'Gen::uniqueArrayOf() key closure must return int|string, got %s',
                get_debug_type($key),
            ));
        }

        return $key;
    }
}
