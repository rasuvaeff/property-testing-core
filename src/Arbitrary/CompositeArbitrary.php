<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Draw;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * A value built by a body that draws several dependent values through a
 * {@see Draw}, shrinking the draws rather than the result: each candidate
 * re-executes the body with one recorded draw replaced by a candidate of its
 * own tree, the draws before it replayed as they were, and the draws after
 * it made again — each from a stream of its own position, so a later draw
 * whose generator did not change comes back as it was, and one whose
 * generator did change (a `max` drawn above a shrunk `min`) is re-drawn
 * from its own stream through the new range rather than replayed as a node
 * of the old one. Earlier draws shrink first.
 *
 * The body sees no randomness other than what {@see Draw::draw()} hands it,
 * and every stream derives from one seed captured at generation time, so a
 * candidate is a pure function of the tape it is given. A body that refuses
 * a shrunk prefix — throws an `Exception` for it, as a validating constructor
 * does — marks that candidate as no value at all: it is skipped with its
 * subtree, the way {@see Shrinkable::map()} skips a refused candidate. An
 * `Error` propagates.
 *
 * A re-executed body can draw more than the original did, so the tree has
 * no finite bound of its own: descent depth is capped at $maxDepth — by
 * default {@see self::MAX_DEPTH}, the number the runner applies to in-body
 * draws. Below the cap every branch is finite and no candidate equals its
 * parent.
 *
 * @template T
 * @implements ArbitraryInterface<T>
 * @api
 */
final readonly class CompositeArbitrary implements ArbitraryInterface
{
    /** Accepted-step bound of one descent through the composite's tape. */
    private const int MAX_DEPTH = 1000;

    /**
     * @param Closure(Draw): T $body Builds one value from the draws it takes through the seam.
     * @param positive-int $maxDepth Accepted-step bound of one descent through the tape.
     */
    public function __construct(
        private Closure $body,
        private int $maxDepth = self::MAX_DEPTH,
    ) {}

    /**
     * @param Random $random The run's stream; one seed is taken from it for the whole subtree.
     *
     * @return Shrinkable<T>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        // One seed for the whole subtree: the body's draws are replayed and
        // regenerated from it, never from the run's stream, so a shrink trial
        // is a pure function of the tape it is given.
        $seed = $random->int(PHP_INT_MIN, PHP_INT_MAX);

        return $this->node([], $seed, $random->edgeCases, 0);
    }

    /**
     * Execute the body over $tape and wrap the outcome as a node.
     *
     * @param list<Shrinkable> $tape
     *
     * @return Shrinkable<T>
     */
    private function node(array $tape, int $seed, EdgeCases $edgeCases, int $depth): Shrinkable
    {
        $draw = new Draw($seed, $edgeCases, $tape);
        $value = ($this->body)($draw);
        $recorded = $draw->recorded();

        /** @var Shrinkable<T> $result */
        $result = Shrinkable::of($value, fn(): \Generator => $this->shrinksFor($value, $recorded, $seed, $edgeCases, $depth));

        return $result;
    }

    /**
     * @param T $value
     * @param list<Shrinkable> $recorded
     *
     * @return \Generator<int, Shrinkable<T>>
     */
    private function shrinksFor(mixed $value, array $recorded, int $seed, EdgeCases $edgeCases, int $depth): \Generator
    {
        if ($depth >= $this->maxDepth) {
            return;
        }

        foreach ($recorded as $position => $node) {
            foreach ($node->shrinks() as $candidate) {
                if ($candidate->value === $node->value) {
                    continue;
                }

                try {
                    $smaller = $this->node([...array_slice($recorded, 0, $position), $candidate], $seed, $edgeCases, $depth + 1);
                } catch (\Exception) {
                    continue;
                }

                if ($smaller->value === $value) {
                    continue;
                }

                yield $smaller;
            }
        }
    }
}
