<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\DocblockTypes;
use Rasuvaeff\PropertyTesting\Internal\ParameterGenerators;
use Rasuvaeff\PropertyTesting\Internal\TypeGenerators;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\RefinedTypeMethods;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The refined string types and the array shapes, read from a signature.
 *
 * Every derived generator is checked against the type's own definition over
 * many draws — `is_numeric()` for `numeric-string`, the key set for a shape —
 * and the forms that must stay unread are refusals naming the parameter.
 */
#[Test]
#[Covers(TypeGenerators::class)]
#[Covers(DocblockTypes::class)]
#[Covers(ParameterGenerators::class)]
final class RefinedTypesTest
{
    private const int DRAWS = 500;

    public function aNumericStringIsAlwaysNumericAndReachesEveryForm(): void
    {
        $forms = ['exponent' => 0, 'dot' => 0, 'leading space' => 0, 'trailing space' => 0, 'sign' => 0];

        foreach ($this->draws('strings', 'numeric') as $value) {
            Assert::true(is_string($value) && is_numeric($value));

            $forms['exponent'] += (int) str_contains($value, 'e');
            $forms['dot'] += (int) (str_starts_with(ltrim($value, '+-'), '.') || str_ends_with($value, '.'));
            $forms['leading space'] += (int) (ltrim($value) !== $value);
            $forms['trailing space'] += (int) (rtrim($value) !== $value);
            $forms['sign'] += (int) str_starts_with($value, '+');
        }

        foreach ($forms as $form => $count) {
            Assert::true($count > 0, sprintf('form "%s" never drawn', $form));
        }
    }

    #[DataProvider('nonFalsyParameters')]
    public function aNonFalsyStringIsNeverEmptyNorZero(string $parameter): void
    {
        foreach ($this->draws('strings', $parameter) as $value) {
            Assert::true(is_string($value) && $value !== '' && $value !== '0');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonFalsyParameters(): iterable
    {
        yield 'non-falsy-string' => ['nonFalsy'];
        yield 'truthy-string' => ['truthy'];
    }

    public function aLowercaseStringHasNoUppercaseUnderEitherDefinition(): void
    {
        $nonEmpty = 0;

        foreach ($this->draws('strings', 'lower') as $value) {
            Assert::true(is_string($value));
            Assert::same(strtolower($value), $value);
            Assert::same(mb_strtolower($value), $value);

            $nonEmpty += (int) ($value !== '');
        }

        Assert::true($nonEmpty > 0);
    }

    public function aNonEmptyLowercaseStringIsNeverEmpty(): void
    {
        foreach ($this->draws('strings', 'nonEmptyLower') as $value) {
            Assert::true(is_string($value) && $value !== '');
            Assert::same(mb_strtolower($value), $value);
        }
    }

    public function aKeyedShapeHasItsRequiredKeysAndSometimesTheOptionalOne(): void
    {
        $withName = 0;
        $withoutName = 0;

        foreach ($this->draws('shapes', 'keyed') as $value) {
            Assert::true(is_array($value));

            $keys = array_keys($value);
            $hasName = array_key_exists('name', $value);

            Assert::same($keys, $hasName ? ['id', 'name', 'with space', 3] : ['id', 'with space', 3]);
            Assert::true(is_int($value['id']) && $value['id'] >= 1);
            Assert::true(is_bool($value['with space']));
            Assert::true(is_int($value[3]) && $value[3] >= 0 && $value[3] <= 9);

            if ($hasName) {
                Assert::true(is_string($value['name']) && $value['name'] !== '');
                ++$withName;
            } else {
                ++$withoutName;
            }
        }

        Assert::true($withName > 0 && $withoutName > 0);
    }

    public function aPositionalShapeIsAList(): void
    {
        foreach ($this->draws('shapes', 'positional') as $value) {
            Assert::true(is_array($value) && array_is_list($value) && count($value) === 2);
            Assert::true(is_int($value[0]) && $value[0] >= 0 && $value[0] <= 9);
            Assert::true(in_array($value[1], ['a', 'b'], strict: true));
        }
    }

    public function aListShapeIsAList(): void
    {
        foreach ($this->draws('shapes', 'list') as $value) {
            Assert::true(is_array($value) && array_is_list($value));
            Assert::true(is_bool($value[0]));
            Assert::same($value[1], 1);
        }
    }

    public function theEmptyShapeIsTheEmptyArray(): void
    {
        foreach ($this->draws('shapes', 'empty') as $value) {
            Assert::same($value, []);
        }
    }

    public function absentIsNotNull(): void
    {
        $absent = 0;
        $null = 0;

        foreach ($this->draws('shapes', 'optionalNullable') as $value) {
            Assert::true(is_array($value));

            if (!array_key_exists('a', $value)) {
                ++$absent;
            } elseif ($value['a'] === null) {
                ++$null;
            }
        }

        Assert::true($absent > 0 && $null > 0);
    }

    public function aShapeNestsInsideACollection(): void
    {
        foreach ($this->draws('shapes', 'nested') as $value) {
            Assert::true(is_array($value));

            foreach ($value as $key => $entry) {
                Assert::true(is_string($key));
                Assert::true(is_array($entry) && array_keys($entry) === ['a', 'b']);
            }
        }
    }

    public function aShapeIsAMemberOfAUnion(): void
    {
        $nulls = 0;
        $shapes = 0;

        foreach ($this->draws('shapes', 'shapeOrNull') as $value) {
            if ($value === null) {
                ++$nulls;

                continue;
            }

            Assert::true(is_array($value) && array_keys($value) === ['a']);
            ++$shapes;
        }

        Assert::true($nulls > 0 && $shapes > 0);
    }

    public function aShapeWrittenAcrossLinesIsRead(): void
    {
        foreach ($this->draws('shapes', 'multiline') as $value) {
            Assert::true(is_array($value) && array_keys($value) === ['a', 'b']);
        }
    }

    public function anOptionalKeyShrinksTowardsAbsent(): void
    {
        $arbitrary = $this->generator('shapes', 'keyed');
        $random = new Random(11);

        do {
            $tree = $arbitrary->generate($random);
        } while (!array_key_exists('name', (array) $tree->value));

        $dropsTheKey = false;

        foreach ($tree->shrinks() as $candidate) {
            $dropsTheKey = $dropsTheKey || !array_key_exists('name', (array) $candidate->value);
        }

        Assert::true($dropsTheKey);
    }

    #[DataProvider('unreadableShapes')]
    public function aShapeFormOutsideTheSubsetIsRefused(string $method, string $type): void
    {
        try {
            Gen::forParameters(new \ReflectionMethod(RefinedTypeMethods::class, $method));
        } catch (\InvalidArgumentException $refusal) {
            Assert::same($refusal->getMessage(), sprintf(
                'Cannot generate arguments for %s::%s(): parameter $shape is documented as %s, which this cannot read; pass an override or #[Generate]',
                RefinedTypeMethods::class,
                $method,
                $type,
            ));

            return;
        }

        Assert::fail('expected an InvalidArgumentException');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableShapes(): iterable
    {
        yield 'unsealed' => ['unsealed', 'array{a: int, ...}'];
        yield 'keyed list' => ['keyedList', 'list{a: int}'];
        yield 'double-quoted key' => ['doubleQuotedKey', 'array{"a": int}'];
        yield 'unreadable value type' => ['unreadableValue', 'array{a: float<0.0, 1.0>}'];
    }

    /**
     * @return list<mixed>
     */
    private function draws(string $method, string $parameter): array
    {
        $arbitrary = $this->generator($method, $parameter);
        $random = new Random(7);
        $values = [];

        for ($i = 0; $i < self::DRAWS; ++$i) {
            $values[] = $arbitrary->generate($random)->value;
        }

        return $values;
    }

    private function generator(string $method, string $parameter): ArbitraryInterface
    {
        return Gen::forParameters(new \ReflectionMethod(RefinedTypeMethods::class, $method))[$parameter];
    }
}
