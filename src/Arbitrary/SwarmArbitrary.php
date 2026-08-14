<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Swarmable;

/**
 * Swarm testing over a choice generator: every generated case may only use
 * some of the variants, drawn afresh and never empty.
 *
 * Drawing uniformly from the whole alphabet, every case looks like every other
 * one — a long run of `oneOf('push', 'pop', 'flush')` almost always contains all
 * three. The bugs that need an operation to be *absent* ("the bag breaks when
 * no flush ever arrives") are then astronomically rare, because avoiding one
 * variant for a hundred draws is a coin flipped a hundred times. Restricting
 * the alphabet per case makes those runs ordinary instead. This is Groce et
 * al., *Swarm Testing* (ISSTA 2012), and it costs one extra draw per case.
 *
 * ```php
 * Gen::swarm(Gen::oneOf('push', 'pop', 'flush'));   // one case sees, say, only 'pop' and 'flush'
 * Gen::swarm(Gen::commands($model, $commands));   // one sequence uses a subset of the commands
 * ```
 *
 * Shrinking stays inside the subset the case was generated from: the tree
 * returned here is the restricted generator's own, so a counterexample found
 * without `flush` cannot shrink to one containing it — which is what makes the
 * finding reproducible at all. The alphabet widens again on the next case, not
 * during a descent.
 *
 * Two consequences worth knowing before reaching for it:
 *
 * - the subset is drawn once per generated value, so it covers exactly the
 *   scope of the generator it wraps. `swarm(commands(...))` restricts a whole
 *   sequence, which is the useful one; `arrayOf(swarm(oneOf(...)))` redraws
 *   per element, which is not swarm testing but noise. Wrap the generator
 *   whose scope you mean;
 * - the counterexample reports the value, not the subset it came from. Seed
 *   replay reproduces both; a counterexample read on its own does not say
 *   which variants were available. Deliberate — the report describes the
 *   input, and the subset is a property of how it was drawn.
 *
 * @template TValue
 * @implements ArbitraryInterface<TValue>
 * @api
 */
final readonly class SwarmArbitrary implements ArbitraryInterface
{
    /** @var Swarmable<TValue> */
    private Swarmable $source;

    /** @var SubsetArbitrary<int> */
    private SubsetArbitrary $variants;

    /**
     * @param ArbitraryInterface<TValue> $source A choice generator — `Gen::oneOf()`, `Gen::elements()`,
     *        `Gen::frequency()`, `Gen::commands()`, or any {@see Swarmable} of your own.
     */
    public function __construct(ArbitraryInterface $source)
    {
        if (!$source instanceof Swarmable) {
            throw new \InvalidArgumentException(sprintf(
                'Swarm requires a choice generator (oneOf, elements, frequency, commands), got %s',
                get_debug_type($source),
            ));
        }

        $count = $source->variantCount();

        if ($count < 1) {
            throw new \InvalidArgumentException(sprintf(
                'Swarm requires at least one variant, %s reports %d',
                get_debug_type($source),
                $count,
            ));
        }

        $this->source = $source;
        // Built once: the alphabet is fixed at construction, and generate() is
        // the per-case path. minSize 1 is the whole invariant — a case with no
        // variants left could not produce a value at all.
        $this->variants = new SubsetArbitrary(range(0, $count - 1), minSize: 1);
    }

    /**
     * @param Random $random The run's source of randomness — one draw for the subset, then the
     *        restricted generator's own draws.
     *
     * @return Shrinkable<TValue>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $kept = $this->variants->generate($random)->value;

        return $this->source->withVariants($kept)->generate($random);
    }
}
