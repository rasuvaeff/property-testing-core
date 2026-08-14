<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Benchmarks;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert\ExpectNoAssertions;
use Testo\Bench;

/**
 * Generation cost, always as a pair.
 *
 * `#[Bench]` measures a method against the callables it is given and ranks
 * them; a lone method has nothing to be ranked against, and the attribute
 * refuses an empty list. That constraint is the right one for a benchmark
 * suite that lives in a repository: an absolute microsecond figure from
 * somebody's laptop says nothing a month later, while "bounding a range costs
 * this much over the unbounded draw" survives the machine it was measured on.
 * So each benchmark below names the baseline its number is only meaningful
 * against.
 */
final class GeneratorBench
{
    /**
     * Bounding an integer range against drawing from the full one — the
     * boundary-bias roll happens either way, so the difference is the cost of
     * the bounds themselves.
     */
    #[ExpectNoAssertions]
    #[Bench(['full range' => [self::class, 'intFullRangeGenerate']], calls: 1_000, iterations: 5)]
    public static function intBetweenGenerate(): int
    {
        return Gen::intBetween(-1_000, 1_000)->generate(new Random(123))->value;
    }

    public static function intFullRangeGenerate(): int
    {
        return Gen::int()->generate(new Random(123))->value;
    }

    /**
     * ASCII against Unicode strings of the same length distribution: what
     * multibyte codepoint assembly adds per draw.
     */
    #[ExpectNoAssertions]
    #[Bench(['unicode' => [self::class, 'stringUnicodeGenerate']], calls: 1_000, iterations: 5)]
    public static function stringAsciiGenerate(): string
    {
        return Gen::stringAscii()->generate(new Random(123))->value;
    }

    public static function stringUnicodeGenerate(): string
    {
        return Gen::string()->generate(new Random(123))->value;
    }

    /**
     * A plain list against one that must stay pairwise distinct — the price of
     * the uniqueness check on every element.
     */
    #[ExpectNoAssertions]
    #[Bench(['unique' => [self::class, 'uniqueArrayOfGenerate']], calls: 1_000, iterations: 5)]
    public static function arrayOfGenerate(): array
    {
        return Gen::arrayOf(Gen::intBetween(1, 10))->generate(new Random(123))->value;
    }

    public static function uniqueArrayOfGenerate(): array
    {
        return Gen::uniqueArrayOf(Gen::intBetween(1, 1_000))->generate(new Random(123))->value;
    }

    /**
     * The same subset draw over a 10k-element source and a 100-element one:
     * each draw runs a partial Fisher-Yates over the index pool, so this is
     * where its scaling in the source size shows up.
     */
    #[ExpectNoAssertions]
    #[Bench(['small source' => [self::class, 'subsetGenerateFromASmallSource']], calls: 100, iterations: 5)]
    public static function subsetGenerateFromALargeSource(): array
    {
        return Gen::subset(range(0, 9_999), minSize: 0, maxSize: 100)
            ->generate(new Random(123))
            ->value;
    }

    public static function subsetGenerateFromASmallSource(): array
    {
        return Gen::subset(range(0, 99), minSize: 0, maxSize: 100)
            ->generate(new Random(123))
            ->value;
    }
}
