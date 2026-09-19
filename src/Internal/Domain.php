<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Enumerable;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Arithmetic and walking of finite domains for the {@see Enumerable}
 * combinators and the exhaustive mode: sizes saturate at `PHP_INT_MAX`
 * instead of overflowing, and a product is walked first-key-major.
 *
 * @internal
 */
final class Domain
{
    private function __construct() {}

    /**
     * The size of $arbitrary's domain, or null when it has none to count.
     *
     * @return ?int<1, max>
     */
    public static function sizeOf(ArbitraryInterface $arbitrary): ?int
    {
        return $arbitrary instanceof Enumerable ? $arbitrary->domainSize() : null;
    }

    /**
     * The product of the domain sizes, saturating; null as soon as one
     * component has no finite domain.
     *
     * @param iterable<ArbitraryInterface> $components
     *
     * @return ?int<1, max>
     */
    public static function product(iterable $components): ?int
    {
        $product = 1;

        foreach ($components as $component) {
            $size = self::sizeOf($component);

            if ($size === null) {
                return null;
            }

            $product = self::times($product, $size);
        }

        return $product;
    }

    /**
     * @param int<1, max> $a
     * @param int<1, max> $b
     *
     * @return int<1, max>
     */
    public static function times(int $a, int $b): int
    {
        return $b > intdiv(PHP_INT_MAX, $a) ? PHP_INT_MAX : $a * $b;
    }

    /**
     * @param int<1, max> $a
     * @param int<0, max> $b
     *
     * @return int<1, max>
     */
    public static function plus(int $a, int $b): int
    {
        return $b > PHP_INT_MAX - $a ? PHP_INT_MAX : $a + $b;
    }

    /**
     * Every combination of one node per component, keyed like $components,
     * the first key varying slowest. Each component is walked afresh per
     * combination of the keys before it, so an iterable that can only be
     * walked once must not be handed in — the enumerables re-create theirs.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, Enumerable> $components
     *
     * @return \Generator<int, array<TKey, Shrinkable>>
     */
    public static function cartesian(array $components): \Generator
    {
        if ($components === []) {
            yield [];

            return;
        }

        $key = array_key_first($components);
        $head = $components[$key];
        unset($components[$key]);

        foreach ($head->enumerate() as $node) {
            foreach (self::cartesian($components) as $rest) {
                yield [$key => $node] + $rest;
            }
        }
    }
}
