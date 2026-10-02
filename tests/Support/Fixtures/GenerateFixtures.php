<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support\Fixtures;

use Rasuvaeff\PropertyTesting\Arbitrary\FloatArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Generate;

/**
 * Signatures carrying {@see Generate} — every form the attribute takes and
 * every way it can be wrong. Reflected, never called.
 */
final class GenerateMethods
{
    /**
     * @return ArbitraryInterface<int>
     */
    public static function evens(): ArbitraryInterface
    {
        return Gen::map(Gen::intBetween(0, 50), static fn(int $half): int => $half * 2);
    }

    /**
     * @return ArbitraryInterface<int>
     */
    public function notStatic(): ArbitraryInterface
    {
        return Gen::constant(1);
    }

    public static function notAnArbitrary(): int
    {
        return 1;
    }

    /**
     * @return ArbitraryInterface<int>
     */
    public static function throws(): ArbitraryInterface
    {
        throw new \LogicException('factory failed');
    }

    public function onEveryParameter(
        #[Generate(new IntArbitrary(5, 5))]
        int $fixed,
        #[Generate('evens')]
        int $even,
    ): void {}

    public function onSomeParameters(#[Generate(new IntArbitrary(1, 1))] int $attributed, int $plain): void {}

    /**
     * @param float<0.0, 1.0> $ratio
     */
    public function overAnUnreadableDocblock(#[Generate(new FloatArbitrary(0.0, 1.0))] float $ratio): void {}

    /**
     * @param int<100, 200> $number
     */
    public function overAReadableDocblock(#[Generate(new IntArbitrary(1, 1))] int $number): void {}

    public function onANullable(#[Generate(new IntArbitrary(3, 3))] ?int $number): void {}

    public function arrayReference(#[Generate([GeneratorFactories::class, 'odd'])] int $number): void {}

    public function stringReference(#[Generate('Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\GeneratorFactories::odd')] int $number): void {}

    public function invokable(#[Generate(new InvokableFactory())] int $number): void {}

    public function onAVariadic(#[Generate(new IntArbitrary(7, 7))] int ...$numbers): void {}

    public function invalidArguments(#[Generate(new IntArbitrary(10, 1))] int $number): void {}

    public function repeated(#[Generate(new IntArbitrary(1, 1))] #[Generate(new IntArbitrary(2, 2))] int $number): void {}

    public function nonStaticFactory(#[Generate('notStatic')] int $number): void {}

    public function notACallableArray(#[Generate([GeneratorFactories::class, 'missing'])] int $number): void {}

    public function notAFactory(#[Generate('noSuchFactory')] int $number): void {}

    public function returnsNoArbitrary(#[Generate('notAnArbitrary')] int $number): void {}

    public function factoryThrows(#[Generate('throws')] int $number): void {}
}

/**
 * Factories referenced from another class.
 */
final class GeneratorFactories
{
    /**
     * @return ArbitraryInterface<int>
     */
    public static function odd(): ArbitraryInterface
    {
        return Gen::map(Gen::intBetween(0, 50), static fn(int $half): int => $half * 2 + 1);
    }
}

final class InvokableFactory
{
    /**
     * @return ArbitraryInterface<int>
     */
    public function __invoke(): ArbitraryInterface
    {
        return Gen::constant(9);
    }
}

/**
 * A constructor read by {@see Gen::forClass()}: the attribute reaches a VO's
 * parameters through the same resolution.
 */
final readonly class AttributedValue
{
    public function __construct(
        #[Generate(new IntArbitrary(42, 42))]
        public int $answer,
        public bool $flag,
    ) {}
}
