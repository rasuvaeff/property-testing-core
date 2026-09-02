<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

/**
 * The contiguous blocks a sequence shrinks by removing, longest first.
 *
 * Shared by every sequence-shaped arbitrary (lists, strings, bytes, maps):
 * a length phase that only halves prefixes can never isolate a failing
 * element in the middle or at the end — `[7, 3, 42]` with "no 42 allowed"
 * would keep `42` in every shorter candidate. Removing aligned blocks of
 * every power-of-two size from every offset (the whole sequence, then
 * halves, quarters, …, single elements) is what QuickCheck's `shrinkList`
 * and Hedgehog's list shrinker do, and it reaches `[42]`.
 *
 * Each removal strictly shortens the sequence, so a shrink tree built from
 * these is finite by induction on length.
 *
 * @internal
 */
final class BlockRemovals
{
    private function __construct()
    {
        // Static helper; not instantiable.
    }

    /**
     * @param int $count The sequence length.
     * @param int $minSize The length a removal may never go below.
     *
     * @return \Generator<int, array{0: int, 1: int}> `[offset, length]` of each block to remove.
     */
    public static function of(int $count, int $minSize): \Generator
    {
        for ($block = $count; $block >= 1; $block = intdiv($block, 2)) {
            if ($count - $block < $minSize) {
                continue;
            }

            for ($offset = 0; $offset + $block <= $count; $offset += $block) {
                yield [$offset, $block];
            }
        }
    }
}
