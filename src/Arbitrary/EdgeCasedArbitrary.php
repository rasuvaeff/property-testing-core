<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * A delegate arbitrary with author-supplied boundary values: one draw in
 * {@see self::BIAS_DENOMINATOR} is one of the edge values instead of what the
 * delegate generates, and every value the delegate does generate shrinks
 * through the edge values first, in the order they were listed, before its
 * own tree.
 *
 * The bias is scoped to this generator and explicit, so it ignores the
 * run-wide {@see \Rasuvaeff\PropertyTesting\Runner\EdgeCases} mode: an
 * opt-in beats a global opt-out. The roll consumes the run's randomness like
 * the built-in boundary bias does, so the delegate's own sequence is
 * unchanged for a seed — only what this wrapper selects is new.
 *
 * An edge value drawn outright shrinks to the edge values listed before it,
 * so the first one is the most-preferred minimum. A candidate equal (`===`)
 * to the value it would replace is skipped: no candidate equals its parent.
 *
 * @template T
 * @implements ArbitraryInterface<T>
 * @api
 */
final readonly class EdgeCasedArbitrary implements ArbitraryInterface
{
    private const int BIAS_DENOMINATOR = 5;

    /** @var non-empty-list<T> */
    private array $edgeCases;

    /**
     * @param ArbitraryInterface<T> $inner
     * @param list<T> $edgeCases
     */
    public function __construct(
        private ArbitraryInterface $inner,
        array $edgeCases,
    ) {
        if ($edgeCases === []) {
            throw new \InvalidArgumentException('Gen::withEdgeCases() requires at least one edge value');
        }

        $this->edgeCases = $edgeCases;
    }

    /**
     * @return Shrinkable<T>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        if ($random->int(1, self::BIAS_DENOMINATOR) === 1) {
            $count = count($this->edgeCases);

            return $this->edge($count === 1 ? 0 : max(0, $random->int(0, $count - 1)));
        }

        return $this->wrap($this->inner->generate($random));
    }

    /**
     * The edge value at $index, shrinking to the edge values listed before it.
     *
     * @param int<0, max> $index
     *
     * @return Shrinkable<T>
     */
    private function edge(int $index): Shrinkable
    {
        $value = $this->edgeCases[$index];

        return Shrinkable::of($value, function () use ($index, $value): \Generator {
            for ($earlier = 0; $earlier < $index; ++$earlier) {
                if ($this->edgeCases[$earlier] === $value) {
                    continue;
                }

                yield $this->edge($earlier);
            }
        });
    }

    /**
     * A delegate-generated node whose candidates start with every edge value.
     *
     * @param Shrinkable<T> $inner
     *
     * @return Shrinkable<T>
     */
    private function wrap(Shrinkable $inner): Shrinkable
    {
        /** @var Shrinkable<T> $result */
        $result = Shrinkable::of($inner->value, fn(): \Generator => $this->shrinksFor($inner));

        return $result;
    }

    /**
     * @param Shrinkable<T> $inner
     *
     * @return \Generator<int, Shrinkable<T>>
     */
    private function shrinksFor(Shrinkable $inner): \Generator
    {
        foreach ($this->edgeCases as $index => $edge) {
            if ($edge === $inner->value) {
                continue;
            }

            yield $this->edge($index);
        }

        foreach ($inner->shrinks() as $smaller) {
            yield $this->wrap($smaller);
        }
    }
}
