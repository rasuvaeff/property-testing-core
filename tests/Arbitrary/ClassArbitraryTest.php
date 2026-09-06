<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\ClassArbitrary;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\Internal\DocblockTypes;
use Rasuvaeff\PropertyTesting\Internal\ParameterGenerators;
use Rasuvaeff\PropertyTesting\Internal\TypeGenerators;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Tests\Support\Aliased\AliasedTypes;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\AnnotatedTypes;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Currency;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Cyclic;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\DocblockClassTypes;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\GenericCollection;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\LiteralsHoldingSeparators;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\NarrowedFloat;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\NativeTypes;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Nested;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\NoConstructor;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\NotInstantiable;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Ordered;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Unreadable;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Validating;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Variadic;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\WithEnumAndDate;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\WrapsNotInstantiable;
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
#[Covers(ParameterGenerators::class)]
#[Covers(DocblockTypes::class)]
#[Covers(TypeGenerators::class)]
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

    public function aLiteralKeepsTheSeparatorsItContains(): void
    {
        // The union splitter walks the type string; a `|` or `,` inside a
        // quoted literal belongs to the literal, not to the type.
        $random = new Random(11);
        $arbitrary = new ClassArbitrary(LiteralsHoldingSeparators::class);

        for ($i = 0; $i < 30; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true(in_array($value->pipe, ['a|b', 'c'], strict: true));
            Assert::true(in_array($value->comma, ['x,y', 'z'], strict: true));
            Assert::true(in_array($value->quote, ["it's", 'plain'], strict: true));
        }
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

    public function classNamesInsideDocblockTypesAreFollowed(): void
    {
        // `list<NativeTypes>`, `Currency|null` (on a mixed parameter), `'a'|null`,
        // `list<\DateTimeImmutable>`, `non-empty-list<Currency>`: every class
        // the docblock names resolves the way the code beneath it would.
        $random = new Random(5);
        $arbitrary = new ClassArbitrary(DocblockClassTypes::class);
        $sawCurrency = false;
        $sawNullCurrency = false;
        $sawStatus = false;
        $sawNullStatus = false;

        for ($i = 0; $i < 60; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::instanceOf($value, DocblockClassTypes::class);
            Assert::true(count($value->items) <= 10);
            Assert::true(count($value->currencies) >= 1);

            foreach ($value->items as $item) {
                Assert::instanceOf($item, NativeTypes::class);
            }

            foreach ($value->dates as $date) {
                Assert::instanceOf($date, \DateTimeImmutable::class);
            }

            foreach ($value->currencies as $currency) {
                Assert::instanceOf($currency, Currency::class);
            }

            Assert::true($value->currency === null || $value->currency instanceof Currency);
            $sawCurrency = $sawCurrency || $value->currency instanceof Currency;
            $sawNullCurrency = $sawNullCurrency || !$value->currency instanceof Currency;
            $sawStatus = $sawStatus || in_array($value->status, ['draft', 'published'], strict: true);
            $sawNullStatus = $sawNullStatus || $value->status === null;
        }

        Assert::true($sawCurrency && $sawNullCurrency);
        Assert::true($sawStatus && $sawNullStatus);
    }

    public function docblockNamesResolveThroughTheFileImports(): void
    {
        // `Money` is `use … as Money`, `Wrapped` comes from a group import,
        // `NativeTypes` from the same group — and none of them lives in the
        // declaring namespace.
        $value = (new ClassArbitrary(AliasedTypes::class))->generate(new Random(3))->value;

        Assert::instanceOf($value, AliasedTypes::class);
        Assert::true(count($value->moneys) >= 1);

        foreach ($value->moneys as $money) {
            Assert::instanceOf($money, Currency::class);
        }

        foreach ($value->wrapped as $wrapped) {
            Assert::instanceOf($wrapped, Nested::class);
        }

        foreach ($value->items as $item) {
            Assert::instanceOf($item, NativeTypes::class);
        }
    }

    public function aDocblockTypeItCannotReadOnAScalarIsRefusedNotWidened(): void
    {
        // `float<0.0, 1.0>` is outside the readable subset. Falling back to the
        // native `float` would generate the whole line for a parameter that
        // promises the unit interval — the widened guess the class rules out.
        try {
            new ClassArbitrary(NarrowedFloat::class);

            Assert::fail('expected the narrowed float to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same(
                $e->getMessage(),
                'Cannot generate ' . NarrowedFloat::class . ': parameter $ratio is documented as float<0.0, 1.0>, which this cannot read; pass an override',
            );
        }
    }

    public function aDocblockTypeItCannotReadOnAClassFallsBackToTheClass(): void
    {
        // Generics on a class type (`NativeTypes<int>`) narrow nothing the
        // constructor can observe, so the native class is generated.
        $value = (new ClassArbitrary(GenericCollection::class))->generate(new Random(1))->value;

        Assert::instanceOf($value, GenericCollection::class);
        Assert::instanceOf($value->inner, NativeTypes::class);
    }

    public function anOverrideNamingNoParameterIsRefused(): void
    {
        try {
            new ClassArbitrary(NativeTypes::class, ['cuont' => Gen::int(), 'label' => Gen::constant('x')]);

            Assert::fail('expected the misspelt override to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same(
                $e->getMessage(),
                'Cannot generate ' . NativeTypes::class . ': override for $cuont names no parameter of it',
            );
        }
    }

    public function aCandidateTheConstructorRejectsIsSkippedWhileShrinking(): void
    {
        // Shrinking `high` toward 0 proposes values below `low`, which the
        // constructor refuses. Without skipInvalid the generated value is
        // trusted, but a refused candidate is still no value at all: it is
        // skipped, and the candidates the constructor accepts are offered.
        $arbitrary = new ClassArbitrary(Ordered::class, [
            'low' => Gen::intBetween(0, 10),
            'high' => Gen::intBetween(0, 10),
        ]);
        $node = null;

        // Without skipInvalid a generated value the constructor refuses
        // propagates (see aRejectedValuePropagatesByDefault); skip those seeds.
        for ($seed = 0; $seed < 1_000 && !$node instanceof Shrinkable; ++$seed) {
            try {
                $generated = $arbitrary->generate(new Random($seed));
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($generated->value instanceof Ordered && $generated->value->low > 0 && $generated->value->high > $generated->value->low) {
                $node = $generated;
            }
        }

        Assert::instanceOf($node, Shrinkable::class);
        Assert::instanceOf($node->value, Ordered::class);

        $children = Trees::childValues($node);

        Assert::true($children !== []);

        $highs = [];

        foreach ($children as $child) {
            Assert::instanceOf($child, Ordered::class);
            Assert::true($child->low <= $child->high);
            $highs[] = $child->high;
        }

        // The candidate high=0 was refused (low > 0); a smaller accepted high follows it.
        Assert::false(in_array(0, $highs, strict: true));
        Assert::true(min($highs) < $node->value->high);
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

    #[ExpectException(GenerationExhaustedException::class)]
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

    public function namesTheChainThatReachedAnUninstantiableClass(): void
    {
        // A value object with a private constructor and named factories (a
        // Duration, a Money) is usually reached from levels up, and naming only
        // the class sends the reader hunting for which parameter asked for it.
        try {
            new ClassArbitrary(WrapsNotInstantiable::class);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('is not instantiable');
            Assert::string($e->getMessage())->contains('reached through');
            Assert::string($e->getMessage())->contains('WrapsNotInstantiable -> ');
            Assert::string($e->getMessage())->contains('NotInstantiable');
        }
    }

    public function theClassAskedForIsNamedWithoutAChain(): void
    {
        // A chain of one says nothing worth reading.
        try {
            new ClassArbitrary(NotInstantiable::class);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::false(str_contains($e->getMessage(), 'reached through'));
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
            'inner' => Gen::constant(new NativeTypes(1, 1.0, 'x', active: true)),
        ], maxDepth: 0);

        Assert::same($arbitrary->generate(new Random(1))->value->inner->label, 'x');
    }
}
