<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates values from a delegate arbitrary, retrying until a predicate holds.
 *
 * Filtering is bounded: after {@see self::MAX_ATTEMPTS} consecutive rejections
 * the generator throws {@see GenerationExhausted} rather than yield a value that
 * fails the predicate — a property never receives an out-of-domain input. Use
 * {@see \Rasuvaeff\PropertyTesting\Assume::that()} inside the property when the
 * rejection rate is high, which skips discarded runs cleanly, or
 * {@see \Rasuvaeff\PropertyTesting\Gen::flatMap()} to construct dependent values
 * without filtering at all.
 *
 * Shrinking walks the inner value's tree, keeping only branches whose value
 * satisfies the predicate (a rejected candidate's subtree is pruned with it).
 *
 * @template TInner
 * @implements ArbitraryInterface<TInner>
 * @api
 */
final readonly class FilteredArbitrary implements ArbitraryInterface
{
    private const int MAX_ATTEMPTS = 100;

    /**
     * @param ArbitraryInterface<TInner> $inner
     * @param Closure(TInner): bool $predicate
     */
    public function __construct(
        private ArbitraryInterface $inner,
        private Closure $predicate,
    ) {}

    /**
     * @return Shrinkable<TInner>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $shrinkable = $this->inner->generate($random);

            if (($this->predicate)($shrinkable->value)) {
                return $this->filtered($shrinkable);
            }
        }

        throw new GenerationExhausted(
            'Gen::filter()',
            self::MAX_ATTEMPTS,
            'the predicate rejected every generated value; widen the source arbitrary, raise the attempt budget, or build dependent values with Gen::flatMap() instead of filtering',
        );
    }

    /**
     * @param Shrinkable<TInner> $shrinkable
     *
     * @return Shrinkable<TInner>
     */
    private function filtered(Shrinkable $shrinkable): Shrinkable
    {
        return Shrinkable::of($shrinkable->value, function () use ($shrinkable): \Generator {
            foreach ($shrinkable->shrinks() as $candidate) {
                if (($this->predicate)($candidate->value)) {
                    yield $this->filtered($candidate);
                }
            }
        });
    }
}
