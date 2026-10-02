<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Generate;
use Rasuvaeff\PropertyTesting\Internal\ParameterGenerators;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\AttributedValue;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\GenerateMethods;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * A generator written on the parameter: where it sits between an override
 * and the declared types, every form of reference it takes, and a refusal
 * naming the parameter for every way it can be wrong — the attribute is read
 * far from where it was written.
 */
#[Test]
#[Covers(Generate::class)]
#[Covers(Gen::class)]
#[Covers(ParameterGenerators::class)]
final class GenerateAttributeTest
{
    public function anAttributedArbitraryIsUsedAsIs(): void
    {
        $generators = Gen::forParameters(self::method('onEveryParameter'));
        $random = new Random(1);

        for ($i = 0; $i < 30; ++$i) {
            Assert::same($generators['fixed']->generate($random)->value, 5);
            Assert::same($generators['even']->generate($random)->value % 2, 0);
        }
    }

    public function anOverrideWinsOverTheAttribute(): void
    {
        $generators = Gen::forParameters(self::method('onEveryParameter'), ['fixed' => Gen::constant(8)]);

        Assert::same($generators['fixed']->generate(new Random(1))->value, 8);
    }

    public function theAttributeWinsOverAReadableDocblock(): void
    {
        $generators = Gen::forParameters(self::method('overAReadableDocblock'));

        Assert::same($generators['number']->generate(new Random(1))->value, 1);
    }

    public function theAttributeStandsInForAnUnreadableDocblock(): void
    {
        // Without the attribute `float<0.0, 1.0>` is refused; with it, the
        // docblock is never read.
        $generators = Gen::forParameters(self::method('overAnUnreadableDocblock'));
        $random = new Random(2);

        for ($i = 0; $i < 30; ++$i) {
            $ratio = $generators['ratio']->generate($random)->value;

            Assert::true($ratio >= 0.0 && $ratio <= 1.0);
        }
    }

    public function aNullableParameterIsNotMadeNullableOnTopOfTheAttribute(): void
    {
        $generators = Gen::forParameters(self::method('onANullable'));
        $random = new Random(3);

        for ($i = 0; $i < 100; ++$i) {
            Assert::same($generators['number']->generate($random)->value, 3);
        }
    }

    public function anAttributeOnAVariadicCountsAsItsOverride(): void
    {
        $generators = Gen::forParameters(self::method('onAVariadic'));

        Assert::same($generators['numbers']->generate(new Random(1))->value, 7);
    }

    #[DataProvider('references')]
    public function everyReferenceFormResolvesToItsFactory(string $method, \Closure $expected): void
    {
        $generators = Gen::forParameters(self::method($method));
        $random = new Random(4);

        for ($i = 0; $i < 20; ++$i) {
            Assert::true($expected($generators['number']->generate($random)->value));
        }
    }

    /**
     * @return iterable<string, array{string, \Closure(mixed): bool}>
     */
    public static function references(): iterable
    {
        $odd = static fn(mixed $value): bool => is_int($value) && $value % 2 === 1;

        yield 'array callable' => ['arrayReference', $odd];
        yield 'Class::method string' => ['stringReference', $odd];
        yield 'invokable object' => ['invokable', static fn(mixed $value): bool => $value === 9];
    }

    public function forClassReadsTheAttributeOnAConstructor(): void
    {
        $random = new Random(5);
        $arbitrary = Gen::forClass(AttributedValue::class);

        for ($i = 0; $i < 20; ++$i) {
            Assert::same($arbitrary->generate($random)->value->answer, 42);
        }
    }

    public function withoutDerivationEveryParameterNeedsAGenerator(): void
    {
        $generators = Gen::forParameters(self::method('onEveryParameter'), derive: false);

        Assert::same(array_keys($generators), ['fixed', 'even']);
    }

    public function withoutDerivationAnOverrideFillsTheGap(): void
    {
        $generators = Gen::forParameters(
            self::method('onSomeParameters'),
            ['plain' => Gen::constant(4)],
            derive: false,
        );

        Assert::same(array_keys($generators), ['attributed', 'plain']);
        Assert::same($generators['plain']->generate(new Random(1))->value, 4);
    }

    public function withoutDerivationAParameterWithNoGeneratorIsRefused(): void
    {
        $this->assertRefused(static fn(): array => Gen::forParameters(self::method('onSomeParameters'), derive: false), sprintf(
            'Cannot generate arguments for %s::onSomeParameters(): parameter $plain has no generator; pass an override or #[Generate]',
            GenerateMethods::class,
        ));
    }

    public function withDerivationTheRestComesFromTheSignature(): void
    {
        $generators = Gen::forParameters(self::method('onSomeParameters'));

        Assert::true(is_int($generators['plain']->generate(new Random(1))->value));
    }

    #[DataProvider('refusals')]
    public function aBrokenAttributeIsRefusedNamingTheParameter(string $method, string $reason): void
    {
        $this->assertRefused(static fn(): array => Gen::forParameters(self::method($method)), sprintf('Cannot generate arguments for %s::%s(): #[Generate] on parameter $number %s', GenerateMethods::class, $method, $reason));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'constructor rejects its arguments' => ['invalidArguments', 'cannot be built: Min must be less than or equal to max'];
        yield 'repeated' => ['repeated', sprintf('cannot be built: Attribute "%s" must not be repeated', Generate::class)];
        yield 'non-static factory' => ['nonStaticFactory', sprintf('names %s::notStatic(), which is not static', GenerateMethods::class)];
        yield 'array that is no callable' => ['notACallableArray', 'takes an ArbitraryInterface or a static factory returning one, got array'];
        yield 'not a factory' => ['notAFactory', 'takes an ArbitraryInterface or a static factory returning one, got "noSuchFactory"'];
        yield 'factory returns no arbitrary' => ['returnsNoArbitrary', 'names a factory that returned int, not an ArbitraryInterface'];
        yield 'factory throws' => ['factoryThrows', 'names a factory that threw: factory failed'];
    }

    public function aBrokenAttributeKeepsTheCauseAsPrevious(): void
    {
        try {
            Gen::forParameters(self::method('factoryThrows'));
        } catch (\InvalidArgumentException $refusal) {
            Assert::instanceOf($refusal->getPrevious(), \LogicException::class);

            return;
        }

        Assert::fail('expected an InvalidArgumentException');
    }

    private static function method(string $name): \ReflectionMethod
    {
        return new \ReflectionMethod(GenerateMethods::class, $name);
    }

    private function assertRefused(\Closure $call, string $message): void
    {
        try {
            $call();
        } catch (\InvalidArgumentException $refusal) {
            Assert::same($refusal->getMessage(), $message);

            return;
        }

        Assert::fail('expected an InvalidArgumentException');
    }
}
