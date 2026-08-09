<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Benchmarks;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Bench;

final class GeneratorBench
{
    #[Bench([], calls: 1_000, iterations: 5)]
    public static function intBetweenGenerate(): int
    {
        return Gen::intBetween(-1_000, 1_000)->generate(new Random(123))->value;
    }

    #[Bench([], calls: 1_000, iterations: 5)]
    public static function stringAsciiGenerate(): string
    {
        return Gen::stringAscii()->generate(new Random(123))->value;
    }

    #[Bench([], calls: 1_000, iterations: 5)]
    public static function arrayOfGenerate(): array
    {
        return Gen::arrayOf(Gen::intBetween(1, 10))->generate(new Random(123))->value;
    }

    #[Bench([], calls: 100, iterations: 5)]
    public static function subsetGenerateFromALargeSource(): array
    {
        // A 10k-element source: construction validates distinctness and each
        // draw runs a partial Fisher-Yates over the index pool.
        return Gen::subset(range(0, 9_999), minSize: 0, maxSize: 100)
            ->generate(new Random(123))
            ->value;
    }
}
