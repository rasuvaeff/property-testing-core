<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Helpers for tree-based assertions: with integrated shrinking a shrink tree
 * can only be obtained by generating, so tests scan seeds deterministically
 * until a node with the wanted value shape appears.
 */
final class Trees
{
    /**
     * Generate from sequential seeds (0, 1, 2, ...) until the produced value
     * satisfies $predicate. Deterministic: the same arbitrary and predicate
     * always land on the same seed.
     *
     * @param Closure(mixed): bool $predicate
     */
    public static function generateWhere(
        ArbitraryInterface $arbitrary,
        Closure $predicate,
        int $maxSeeds = 10_000,
    ): Shrinkable {
        for ($seed = 0; $seed < $maxSeeds; ++$seed) {
            $shrinkable = $arbitrary->generate(new Random($seed));

            if ($predicate($shrinkable->value)) {
                return $shrinkable;
            }
        }

        throw new \RuntimeException(sprintf('No seed below %d produced a matching value', $maxSeeds));
    }

    /**
     * The direct children's values of a node, in shrink order.
     *
     * @return list<mixed>
     */
    public static function childValues(Shrinkable $shrinkable): array
    {
        $values = [];

        foreach ($shrinkable->shrinks() as $child) {
            /** @var mixed $childValue */
            $childValue = $child->value;
            $values[] = $childValue;
        }

        return $values;
    }

    /**
     * Greedy descent: repeatedly move to the first child whose value still
     * satisfies $fails, until no child does — the runner's shrink loop for a
     * single parameter. Returns the minimal still-failing node.
     *
     * @param Closure(mixed): bool $fails
     */
    public static function descendWhile(Shrinkable $shrinkable, Closure $fails): Shrinkable
    {
        do {
            $descended = false;

            foreach ($shrinkable->shrinks() as $child) {
                if ($fails($child->value)) {
                    $shrinkable = $child;
                    $descended = true;

                    break;
                }
            }
        } while ($descended);

        return $shrinkable;
    }

    /**
     * Every value in the tree down to $depth levels below the root (root
     * excluded), breadth-first. Guards recursive invariants (e.g. "all
     * candidates satisfy the filter predicate").
     *
     * @return list<mixed>
     */
    public static function valuesToDepth(Shrinkable $shrinkable, int $depth): array
    {
        if ($depth <= 0) {
            return [];
        }

        $values = [];

        foreach ($shrinkable->shrinks() as $child) {
            /** @var mixed $childValue */
            $childValue = $child->value;
            $values[] = $childValue;

            foreach (self::valuesToDepth($child, $depth - 1) as $deeper) {
                /** @var mixed $deeper */
                $values[] = $deeper;
            }
        }

        return $values;
    }
}
