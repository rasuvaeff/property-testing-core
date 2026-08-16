<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Benchmarks;

/**
 * Signatures {@see ForParametersBench} derives from. Local to the Benchmarks
 * suite on purpose: the suite only loads this directory, so the test fixtures
 * are out of reach. Reflected, never called.
 */
final class BenchSignatures
{
    /**
     * @param int<1, 300> $base
     * @param int<1, 86400> $cap
     */
    public function annotated(int $base, int $cap, bool $flag): void {}

    /** The same shape as {@see annotated()}, with no docblock to read. */
    public function unannotated(int $base, int $cap, bool $flag): void {}
}

/**
 * The same annotated shape as a constructor, for the `forClass` pair.
 */
final readonly class AnnotatedConstructor
{
    /**
     * @param int<1, 300> $base
     * @param int<1, 86400> $cap
     */
    public function __construct(
        public int $base,
        public int $cap,
        public bool $flag,
    ) {}
}
