<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Benchmarks;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert\ExpectNoAssertions;
use Testo\Bench;

/**
 * What each way of obtaining a property's generators costs, always as a pair
 * (see {@see GeneratorBench} for why a lone number is refused): deriving from
 * the signature against writing the map by hand, the docblock against the
 * native types, deriving once against re-deriving per draw. These are the
 * usage variants an adapter's `auto` mode chooses between, priced.
 */
final class ForParametersBench
{
    /**
     * Deriving from an annotated signature against its two alternatives over
     * the same three parameters: the identical signature without a docblock
     * (what the `@param` parse itself adds), and the hand-written map (what
     * reflection as a whole adds over just writing the generators down).
     */
    #[ExpectNoAssertions]
    #[Bench([
        'no docblock to read' => [self::class, 'deriveFromNativeTypes'],
        'hand-written map' => [self::class, 'handWrittenMap'],
    ], calls: 1_000, iterations: 5)]
    public static function deriveFromAnnotatedSignature(): array
    {
        return Gen::forParameters(new \ReflectionMethod(BenchSignatures::class, 'annotated'));
    }

    public static function deriveFromNativeTypes(): array
    {
        return Gen::forParameters(new \ReflectionMethod(BenchSignatures::class, 'unannotated'));
    }

    public static function handWrittenMap(): array
    {
        return [
            'base' => Gen::intBetween(1, 300),
            'cap' => Gen::intBetween(1, 86_400),
            'flag' => Gen::bool(),
        ];
    }

    /**
     * A full override map against full derivation — the overrides
     * short-circuit resolution parameter by parameter, so this pair is the
     * explicit-provider variant priced against the auto-derived one on the
     * same signature.
     */
    #[ExpectNoAssertions]
    #[Bench(['derived from the signature' => [self::class, 'deriveFromAnnotatedSignatureAgain']], calls: 1_000, iterations: 5)]
    public static function deriveWithFullOverrides(): array
    {
        return Gen::forParameters(new \ReflectionMethod(BenchSignatures::class, 'annotated'), [
            'base' => Gen::intBetween(1, 300),
            'cap' => Gen::intBetween(1, 86_400),
            'flag' => Gen::bool(),
        ]);
    }

    public static function deriveFromAnnotatedSignatureAgain(): array
    {
        return Gen::forParameters(new \ReflectionMethod(BenchSignatures::class, 'annotated'));
    }

    /**
     * The map is a pure function of the signature, so an adapter derives it
     * once per property and draws from it every run. This pair prices the
     * tempting shortcut of re-deriving on every draw instead — ten draws each
     * way.
     */
    #[ExpectNoAssertions]
    #[Bench(['re-derive per draw' => [self::class, 'reDerivePerDraw']], calls: 200, iterations: 5)]
    public static function deriveOnceDrawMany(): array
    {
        $random = new Random(123);
        $generators = Gen::forParameters(new \ReflectionMethod(BenchSignatures::class, 'annotated'));
        $values = [];

        for ($i = 0; $i < 10; ++$i) {
            foreach ($generators as $name => $generator) {
                $values[$name] = $generator->generate($random)->value;
            }
        }

        return $values;
    }

    public static function reDerivePerDraw(): array
    {
        $random = new Random(123);
        $values = [];

        for ($i = 0; $i < 10; ++$i) {
            foreach (Gen::forParameters(new \ReflectionMethod(BenchSignatures::class, 'annotated')) as $name => $generator) {
                $values[$name] = $generator->generate($random)->value;
            }
        }

        return $values;
    }

    /**
     * The two derivations over the same constructor: `forClass` (arguments
     * assembled into an instance) against `forParameters` (the bare map) —
     * the difference is the record assembly and the instantiation mapping
     * layered on top of the shared resolution.
     */
    #[ExpectNoAssertions]
    #[Bench(['forParameters on the constructor' => [self::class, 'deriveConstructorMap']], calls: 1_000, iterations: 5)]
    public static function deriveForClass(): mixed
    {
        return Gen::forClass(AnnotatedConstructor::class);
    }

    public static function deriveConstructorMap(): array
    {
        return Gen::forParameters(new \ReflectionMethod(AnnotatedConstructor::class, '__construct'));
    }
}
