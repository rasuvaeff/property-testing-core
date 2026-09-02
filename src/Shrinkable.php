<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use Closure;

/**
 * A generated value together with a lazy tree of progressively "smaller"
 * variants of it — the unit of integrated shrinking.
 *
 * Every {@see ArbitraryInterface::generate()} call returns one of these. The
 * property runner reads {@see $value}, and when the property fails it walks
 * {@see shrinks()}: each child is a smaller candidate that carries its own
 * subtree, so accepting a candidate immediately provides the next round of
 * even smaller candidates. Because the tree is built at generation time, a
 * transformed arbitrary ({@see Gen::map()}, {@see Gen::flatMap()}) shrinks in
 * the source domain and re-applies the transformation — no inverse function
 * is ever needed.
 *
 * Children are produced lazily (the closure runs only when the runner asks),
 * so building a node costs nothing until shrinking actually happens.
 *
 * @template TValue The type of the carried value
 * @api
 */
final readonly class Shrinkable
{
    /**
     * @param TValue $value
     * @param Closure(): iterable<self<TValue>> $shrinks
     */
    private function __construct(
        public mixed $value,
        private Closure $shrinks,
    ) {}

    /**
     * A value with no smaller variants (terminal node).
     *
     * @template T
     *
     * @param T $value
     *
     * @return self<T>
     */
    public static function leaf(mixed $value): self
    {
        return new self($value, static fn(): array => []);
    }

    /**
     * A value with lazily-computed smaller variants, ordered most aggressive
     * first (typically toward a zero/empty/identity element).
     *
     * @template T
     *
     * @param T $value
     * @param Closure(): iterable<self<T>> $shrinks
     *
     * @return self<T>
     */
    public static function of(mixed $value, Closure $shrinks): self
    {
        return new self($value, $shrinks);
    }

    /**
     * The smaller variants of this value, each with its own subtree.
     *
     * @return iterable<self<TValue>>
     */
    public function shrinks(): iterable
    {
        return ($this->shrinks)();
    }

    /**
     * Transform the whole tree through a pure function: the value and, lazily,
     * every shrink candidate. This is what makes {@see Gen::map()} shrink.
     *
     * A candidate the transformation refuses — it throws an `Exception` for
     * the smaller source value, the way a validating constructor does — is
     * not a smaller value, it is no value at all: the candidate is skipped
     * together with its subtree and the enumeration moves on to the next
     * sibling. Only exceptions are treated as a refusal; an `Error` (a
     * `TypeError` above all) says the transformation itself is broken and
     * propagates, so a bug does not silently empty a value space.
     *
     * The root value is transformed eagerly, and an exception there
     * propagates from this call: the value being mapped was generated, not
     * proposed as a smaller variant, so there is nothing to skip to.
     *
     * @template TOutput
     *
     * @param Closure(TValue): TOutput $map
     *
     * @return self<TOutput>
     */
    public function map(Closure $map): self
    {
        $shrinks = $this->shrinks;

        return new self($map($this->value), static function () use ($map, $shrinks): \Generator {
            foreach ($shrinks() as $shrinkable) {
                try {
                    $mapped = $shrinkable->map($map);
                } catch (\Exception) {
                    continue;
                }

                yield $mapped;
            }
        });
    }
}
