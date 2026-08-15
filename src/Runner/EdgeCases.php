<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * Whether the numeric generators keep biasing toward their boundary values.
 *
 * The bias is normally what you want: bugs cluster at `0`, `±1` and a range's
 * own ends far more than in the uniform interior, so roughly one draw in five
 * returns an edge value rather than a uniform one.
 *
 * It stops being what you want when the edges are exactly what the property
 * cannot use — a body that discards `0` through `Assume::that()`, or a
 * generator whose range ends violate a precondition. Then one run in five is
 * spent producing a value the property throws away, and the discard budget
 * carries the cost.
 *
 * @api
 */
enum EdgeCases
{
    /** Edge values mixed into random generation — the default, and jqwik's `MIXIN`. */
    case Mixin;

    /**
     * Uniform generation only.
     *
     * The roll that would have chosen an edge value still happens, so the two
     * modes stay aligned on the same seed: turning edge cases off changes
     * which values appear, not the whole sequence after the first one. That
     * makes a suite comparable across the switch instead of unrelated to
     * itself.
     */
    case None;
}
