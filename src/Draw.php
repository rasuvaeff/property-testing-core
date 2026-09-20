<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use Rasuvaeff\PropertyTesting\Runner\EdgeCases;

/**
 * The draw seam a {@see Gen::composite()} body receives: every value the
 * body needs comes through {@see draw()}, and each draw is one position on
 * a tape the composite replays and shrinks — the same model as in-body
 * {@see Gen::draw()}, scoped to one generated value instead of a whole run.
 *
 *     Gen::composite(static fn (Draw $d): Interval => new Interval(
 *         $min = $d->draw(Gen::datetime()),
 *         $d->draw(Gen::datetime(min: $min)),
 *     ));
 *
 * Instances are handed to the body by the engine; there is nothing to
 * construct by hand.
 *
 * @api
 */
final class Draw
{
    private int $position = 0;

    /**
     * Nodes served to the body so far, in draw order.
     *
     * @var list<Shrinkable>
     */
    private array $recorded = [];

    /**
     * @param int $seed The composite's captured seed; each position derives its own stream from it.
     * @param EdgeCases $edgeCases The boundary-value mode of the run, carried into every position's stream.
     * @param list<Shrinkable> $tape Nodes to replay by position; draws past its end generate anew.
     *
     * @internal Constructed by {@see Arbitrary\CompositeArbitrary}.
     */
    public function __construct(
        private readonly int $seed,
        private readonly EdgeCases $edgeCases,
        private readonly array $tape = [],
    ) {}

    /**
     * One value from $arbitrary. Replayed from the tape while it lasts —
     * served as recorded, not re-validated against $arbitrary — and past its
     * end generated from a stream of this position's own: the same position
     * draws from the same stream on every execution, so a draw that follows
     * a shrunk one comes back unchanged when its generator did not change,
     * and is re-drawn through the new range when it did.
     *
     * @template T
     *
     * @param ArbitraryInterface<T> $arbitrary What to draw when the tape has no node for this position.
     *
     * @return T
     */
    public function draw(ArbitraryInterface $arbitrary): mixed
    {
        $node = $this->position < count($this->tape)
            ? $this->tape[$this->position]
            : $arbitrary->generate(new Random(crc32($this->seed . '/' . $this->position), $this->edgeCases));

        $this->recorded[] = $node;
        ++$this->position;
        /** @var T $value */
        $value = $node->value;

        return $value;
    }

    /**
     * The nodes the body actually used, in order.
     *
     * @return list<Shrinkable>
     *
     * @internal Read by {@see Arbitrary\CompositeArbitrary} after the body returns.
     */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
