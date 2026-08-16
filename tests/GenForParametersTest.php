<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\ParameterGenerators;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\AnnotatedTypes;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Currency;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\NativeTypes;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\PropertyMethods;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * What a signature already declares, as the map of generators a property
 * needs — the resolution rules of {@see Gen::forClass()} applied to a method
 * or closure instead of a constructor.
 *
 * Two groups again: the derivations (the docblock wins over the native type,
 * a partial override leaves the rest derived) and the refusals — every type
 * this cannot read has to be an exception naming the function and the
 * parameter, because a widened guess turns into somebody else's failing test.
 */
#[Test]
#[Covers(Gen::class)]
#[Covers(ParameterGenerators::class)]
final class GenForParametersTest
{
    public function readsNativeTypesInSignatureOrder(): void
    {
        $generators = Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'native'));

        Assert::same(array_keys($generators), ['count', 'ratio', 'label', 'active']);

        $random = new Random(1);

        Assert::true(is_int($generators['count']->generate($random)->value));
        Assert::true(is_float($generators['ratio']->generate($random)->value));
        Assert::true(is_string($generators['label']->generate($random)->value));
        Assert::true(is_bool($generators['active']->generate($random)->value));
    }

    public function theDocblockWinsOverTheNativeType(): void
    {
        $generators = Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'annotated'));
        $random = new Random(3);

        for ($i = 0; $i < 50; ++$i) {
            $base = $generators['base']->generate($random)->value;
            $cap = $generators['cap']->generate($random)->value;

            // int<1, 300> and int<1, 86400> — the native type says only "int".
            Assert::true($base >= 1 && $base <= 300);
            Assert::true($cap >= 1 && $cap <= 86_400);
        }
    }

    public function readsTheSameConstructorForClassReads(): void
    {
        // Parity on the very signature ClassArbitrary derives from: the same
        // rules produce the same value spaces when handed the constructor as a
        // plain method.
        $generators = Gen::forParameters(new \ReflectionMethod(AnnotatedTypes::class, '__construct'));
        $random = new Random(5);

        for ($i = 0; $i < 50; ++$i) {
            $percent = $generators['percent']->generate($random)->value;
            $name = $generators['name']->generate($random)->value;
            $status = $generators['status']->generate($random)->value;

            Assert::true($percent >= 0 && $percent <= 100);
            Assert::true($name !== '');
            Assert::true(in_array($status, ['draft', 'published'], strict: true));
        }
    }

    public function anOverrideWinsOverEveryDeclaredType(): void
    {
        $generators = Gen::forParameters(
            new \ReflectionMethod(PropertyMethods::class, 'annotated'),
            ['cap' => Gen::constant(7)],
        );

        Assert::same($generators['cap']->generate(new Random(1))->value, 7);
    }

    public function aPartialOverrideLeavesTheRestDerived(): void
    {
        // The contract an adapter's auto mode builds on: a provider covers the
        // parameters it names, the signature covers the rest.
        $generators = Gen::forParameters(
            new \ReflectionMethod(PropertyMethods::class, 'annotated'),
            ['base' => Gen::constant(300)],
        );
        $random = new Random(7);

        Assert::same(array_keys($generators), ['base', 'cap', 'flag']);
        Assert::same($generators['base']->generate($random)->value, 300);

        for ($i = 0; $i < 30; ++$i) {
            $cap = $generators['cap']->generate($random)->value;

            Assert::true($cap >= 1 && $cap <= 86_400);
        }
    }

    public function classTypedParametersAreFollowed(): void
    {
        $generators = Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'withClass'));

        Assert::instanceOf($generators['inner']->generate(new Random(11))->value, NativeTypes::class);
    }

    public function enumsAndDatesAreGeneratedByTheirOwnFactories(): void
    {
        $generators = Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'withEnumAndDate'));
        $random = new Random(9);
        $currencies = [];

        for ($i = 0; $i < 30; ++$i) {
            Assert::instanceOf($generators['at']->generate($random)->value, \DateTimeImmutable::class);
            $currencies[$generators['currency']->generate($random)->value->name] = true;
        }

        Assert::same(count($currencies), count(Currency::cases()));
    }

    public function aNullableNativeTypeReachesBothSides(): void
    {
        $generators = Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'nullableNative'));
        $random = new Random(13);
        $seen = ['null' => false, 'int' => false];

        for ($i = 0; $i < 50; ++$i) {
            $value = $generators['maybe']->generate($random)->value;

            $seen[$value === null ? 'null' : 'int'] = true;
        }

        Assert::same($seen, ['null' => true, 'int' => true]);
    }

    public function worksOnAClosure(): void
    {
        $closure = /** @param int<0, 9> $digit */ function (int $digit, bool $flag): void {};

        $generators = Gen::forParameters(new \ReflectionFunction($closure));
        $random = new Random(17);

        Assert::same(array_keys($generators), ['digit', 'flag']);

        for ($i = 0; $i < 30; ++$i) {
            $digit = $generators['digit']->generate($random)->value;

            Assert::true($digit >= 0 && $digit <= 9);
        }
    }

    public function aFunctionWithoutParametersIsAnEmptyMap(): void
    {
        Assert::same(Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'withoutParameters')), []);
    }

    public function theSameSeedProducesTheSameValues(): void
    {
        $method = new \ReflectionMethod(PropertyMethods::class, 'annotated');

        $first = array_map(
            static fn(mixed $generator): mixed => $generator->generate(new Random(21))->value,
            Gen::forParameters($method),
        );
        $second = array_map(
            static fn(mixed $generator): mixed => $generator->generate(new Random(21))->value,
            Gen::forParameters($method),
        );

        Assert::same($first, $second);
    }

    public function rejectsATypeItCannotReadNamingFunctionAndParameter(): void
    {
        // A bare `array` is not a value space: nothing says what is in it.
        try {
            Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'unreadable'));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('arguments for');
            Assert::string($e->getMessage())->contains('PropertyMethods::unreadable()');
            Assert::string($e->getMessage())->contains('parameter $anything is typed array');
            Assert::string($e->getMessage())->contains('pass an override');
        }
    }

    public function rejectsMixed(): void
    {
        try {
            Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'withMixed'));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('parameter $anything is typed mixed');
        }
    }

    public function rejectsAParameterWithoutAType(): void
    {
        try {
            Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'untyped'));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('parameter $anything');
            Assert::string($e->getMessage())->contains('no usable type');
        }
    }

    public function rejectsAVariadicParameter(): void
    {
        try {
            Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'variadic'));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('PropertyMethods::variadic()');
            Assert::string($e->getMessage())->contains('parameter $numbers is variadic');
        }
    }

    public function anOverrideSilencesARefusal(): void
    {
        $generators = Gen::forParameters(
            new \ReflectionMethod(PropertyMethods::class, 'unreadable'),
            ['anything' => Gen::constant([1, 2, 3])],
        );

        Assert::same($generators['anything']->generate(new Random(1))->value, [1, 2, 3]);
    }

    public function rejectsACycleAndNamesTheChain(): void
    {
        // Without this the reflection walk would recurse until the stack ends.
        try {
            Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'withCycle'));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('reachable from itself');
            Assert::string($e->getMessage())->contains('Cyclic -> ');
        }
    }

    public function refusesToFollowClassesBeyondTheDepthLimit(): void
    {
        try {
            Gen::forParameters(new \ReflectionMethod(PropertyMethods::class, 'withClass'), maxDepth: 0);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('maximum depth reached');
        }
    }
}
