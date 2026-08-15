<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\ClassArbitrary;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\AnnotatedTypes;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Currency;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Cyclic;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\NativeTypes;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Nested;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\NoConstructor;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\NotInstantiable;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Unreadable;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Validating;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Variadic;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\WithEnumAndDate;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * What a constructor already declares, as a generator.
 *
 * The tests that matter most are the annotated ones: reading `int<0, 100>`
 * rather than `int` is the difference between a generator that matches the
 * domain and one whose values a validating constructor rejects four times in
 * five. The second group is the refusals — every type this cannot read has to
 * be an exception naming the parameter, because a widened guess turns into
 * somebody else's failing test.
 */
#[Test]
#[Covers(ClassArbitrary::class)]
final class ClassArbitraryTest
{
    public function generatesFromNativeConstructorTypes(): void
    {
        $random = new Random(1);
        $arbitrary = new ClassArbitrary(NativeTypes::class);

        for ($i = 0; $i < 20; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::instanceOf($value, NativeTypes::class);
            Assert::true($value->ratio >= -1_000_000.0 && $value->ratio <= 1_000_000.0);
        }
    }

    public function readsTheAnnotatedValueSpaceRatherThanTheNativeOne(): void
    {
        $random = new Random(3);
        $arbitrary = new ClassArbitrary(AnnotatedTypes::class);

        for ($i = 0; $i < 50; ++$i) {
            $value = $arbitrary->generate($random)->value;

            // int<0, 100> — the native type says only "int".
            Assert::true($value->percent >= 0 && $value->percent <= 100);
            // positive-int
            Assert::true($value->quantity >= 1);
            // non-empty-string
            Assert::true($value->name !== '');
            // list<int>
            Assert::same(array_values($value->ids), $value->ids);
            // array<non-empty-string, int>
            foreach ($value->counters as $key => $count) {
                Assert::true($key !== '');
                Assert::true(is_int($count));
            }
            // 'draft'|'published' — a domain spelled out in the type
            Assert::true(in_array($value->status, ['draft', 'published'], strict: true));
            // ?non-empty-string
            Assert::true($value->note === null || $value->note !== '');
        }
    }

    public function everyLiteralOfAUnionIsReachable(): void
    {
        // A closed set that only ever produced one of its values would satisfy
        // the assertion above and be useless.
        $random = new Random(5);
        $arbitrary = new ClassArbitrary(AnnotatedTypes::class);
        $seen = [];

        for ($i = 0; $i < 50; ++$i) {
            $seen[$arbitrary->generate($random)->value->status] = true;
        }

        Assert::same(count($seen), 2);
    }

    public function enumsAndDatesAreGeneratedByTheirOwnFactories(): void
    {
        $random = new Random(9);
        $arbitrary = new ClassArbitrary(WithEnumAndDate::class);
        $currencies = [];

        for ($i = 0; $i < 30; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::instanceOf($value->at, \DateTimeImmutable::class);
            $currencies[$value->currency->name] = true;
        }

        Assert::same(count($currencies), count(Currency::cases()));
    }

    public function classTypedParametersAreFollowed(): void
    {
        $value = (new ClassArbitrary(Nested::class))->generate(new Random(11))->value;

        Assert::instanceOf($value->inner, NativeTypes::class);
    }

    public function anOverrideWinsOverEveryDeclaredType(): void
    {
        $random = new Random(13);
        $arbitrary = new ClassArbitrary(AnnotatedTypes::class, ['percent' => Gen::constant(7)]);

        for ($i = 0; $i < 10; ++$i) {
            Assert::same($arbitrary->generate($random)->value->percent, 7);
        }
    }

    public function aClassWithoutAConstructorIsStillGenerated(): void
    {
        Assert::instanceOf(
            (new ClassArbitrary(NoConstructor::class))->generate(new Random(1))->value,
            NoConstructor::class,
        );
    }

    public function theSameSeedProducesTheSameInstance(): void
    {
        $arbitrary = new ClassArbitrary(NativeTypes::class);

        $first = $arbitrary->generate(new Random(21))->value;
        $second = $arbitrary->generate(new Random(21))->value;

        Assert::same([$first->count, $first->label, $first->active], [$second->count, $second->label, $second->active]);
    }

    public function theInstanceShrinksThroughItsArguments(): void
    {
        // Integrated shrinking survives the mapping: the object is rebuilt from
        // smaller arguments rather than being a leaf.
        $node = Trees::generateWhere(
            new ClassArbitrary(NativeTypes::class),
            static fn(mixed $value): bool => $value instanceof NativeTypes && $value->count > 1_000,
        );

        $counts = array_map(
            static fn(mixed $value): int => $value instanceof NativeTypes ? $value->count : PHP_INT_MAX,
            Trees::childValues($node),
        );

        Assert::true($counts !== []);
        Assert::true(min($counts) < $node->value->count);
    }

    public function aRejectedValuePropagatesByDefault(): void
    {
        // The default says what is true: the generator does not match the
        // domain. An override or a narrower annotation is the fix, and a
        // silent discard would hide both.
        try {
            (new ClassArbitrary(Validating::class))->generate(new Random(2));

            Assert::fail('expected the constructor to reject the generated value');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Amount must be greater than or equal to 0');
        }
    }

    public function skipInvalidDiscardsAndRedraws(): void
    {
        $random = new Random(2);
        $arbitrary = new ClassArbitrary(Validating::class, skipInvalid: true);

        for ($i = 0; $i < 20; ++$i) {
            Assert::true($arbitrary->generate($random)->value->amount >= 0);
        }
    }

    #[ExpectException(GenerationExhausted::class)]
    public function skipInvalidGivesUpWhenNothingIsEverValid(): void
    {
        // Nothing the generator can produce satisfies the constructor, so the
        // budget runs out — the same outcome Gen::filter() reports.
        (new ClassArbitrary(Validating::class, ['amount' => Gen::intBetween(PHP_INT_MIN, -1)], skipInvalid: true))
            ->generate(new Random(1));
    }

    public function rejectsAnAbstractClass(): void
    {
        try {
            new ClassArbitrary(NotInstantiable::class);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('not instantiable');
        }
    }

    public function rejectsATypeItCannotRead(): void
    {
        // A bare `array` is not a value space: nothing says what is in it.
        try {
            new ClassArbitrary(Unreadable::class);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('parameter $anything is typed array');
            Assert::string($e->getMessage())->contains('pass an override');
        }
    }

    public function rejectsAVariadicConstructor(): void
    {
        try {
            new ClassArbitrary(Variadic::class);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('is variadic');
        }
    }

    public function rejectsACycleAndNamesTheChain(): void
    {
        // Without this the reflection walk would recurse until the stack ends.
        try {
            new ClassArbitrary(Cyclic::class);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('reachable from itself');
            Assert::string($e->getMessage())->contains('Cyclic -> ');
        }
    }

    public function refusesToFollowClassesBeyondTheDepthLimit(): void
    {
        try {
            new ClassArbitrary(Nested::class, maxDepth: 0);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('maximum depth reached');
        }
    }

    public function anOverrideBreaksACycleThatWouldOtherwiseBeRefused(): void
    {
        // The documented escape hatch, exercised rather than promised.
        $arbitrary = new ClassArbitrary(Nested::class, [
            'inner' => Gen::constant(new NativeTypes(1, 1.0, 'x', true)),
        ], maxDepth: 0);

        Assert::same($arbitrary->generate(new Random(1))->value->inner->label, 'x');
    }
}
