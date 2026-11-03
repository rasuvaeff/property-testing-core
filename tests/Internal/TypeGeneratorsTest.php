<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\DocblockTypes;
use Rasuvaeff\PropertyTesting\Internal\ParameterGenerators;
use Rasuvaeff\PropertyTesting\Internal\TypeGenerators;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Priority;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The docblock type reader driven directly: the exact domain of each refined
 * integer, the literal unions, the `T[]` form, the forms that must stay
 * unread, and the file-import edge cases (no file, an unreadable file, a
 * mixed group import).
 */
#[Test]
#[Covers(TypeGenerators::class)]
#[Covers(DocblockTypes::class)]
#[Covers(ParameterGenerators::class)]
final class TypeGeneratorsTest
{
    private const int DRAWS = 1000;

    #[DataProvider('refinedIntegers')]
    public function aRefinedIntegerSpansExactlyItsDomain(string $type, int $min, int $max): void
    {
        $values = $this->draws(self::read($type));

        Assert::same(min($values), $min);
        Assert::same(max($values), $max);
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function refinedIntegers(): iterable
    {
        yield 'negative-int' => ['negative-int', PHP_INT_MIN, -1];
        yield 'non-negative-int' => ['non-negative-int', 0, PHP_INT_MAX];
        yield 'non-positive-int' => ['non-positive-int', PHP_INT_MIN, 0];
    }

    #[DataProvider('constants')]
    public function aBooleanLiteralTypeIsThatValueOnly(string $type, bool $value): void
    {
        Assert::same(array_values(array_unique($this->draws(self::read($type)))), [$value]);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function constants(): iterable
    {
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
    }

    public function anIntegerLiteralUnionYieldsExactlyItsIntegers(): void
    {
        $values = array_values(array_unique($this->draws(self::read('1|2|-3'))));
        sort($values);

        Assert::same($values, [-3, 1, 2]);
    }

    public function keywordLiteralsKeepTheirOwnValues(): void
    {
        $seen = [];

        foreach ($this->draws(self::read("'a'|true|false|null")) as $value) {
            $seen[var_export($value, true)] = true;
        }

        ksort($seen);

        Assert::same(array_keys($seen), ["'a'", 'NULL', 'false', 'true']);
    }

    public function theBracketSuffixIsAListOfTheElementTypeUpToTen(): void
    {
        $sizes = [];

        foreach ($this->draws(self::read('int<0, 9>[]')) as $value) {
            Assert::true(is_array($value) && array_is_list($value));

            foreach ($value as $element) {
                Assert::true(is_int($element) && $element >= 0 && $element <= 9);
            }

            $sizes[\count($value)] = true;
        }

        ksort($sizes);

        Assert::same(array_keys($sizes), range(0, 10));
    }

    #[DataProvider('unreadable')]
    public function aTypeOutsideTheSubsetIsNotRead(string $type): void
    {
        Assert::null(self::read($type));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'unknown class element' => ['Nope[]'];
        yield 'keyed list' => ['list<int, int>'];
        yield 'three arguments' => ['array<int, int, int>'];
        yield 'union with an unknown member' => ['int|Nope'];
    }

    public function aFunctionWithoutAFileResolvesNoImports(): void
    {
        Assert::same(DocblockTypes::imports(new \ReflectionFunction('strlen')), ['namespace' => '', 'aliases' => []]);

        $generators = Gen::forParameters(new \ReflectionFunction('strlen'));

        Assert::true(is_string($generators['string']->generate(new Random(1))->value));
    }

    public function aFileGoneBeforeItsImportsAreReadResolvesGlobalNames(): void
    {
        $function = self::closureFromFile(<<<'PHP'
            <?php

            namespace Gone;

            return /** @param list<DateTimeImmutable> $dates */ static function (array $dates): void {};
            PHP, unlink: true);

        Assert::same(DocblockTypes::imports($function), ['namespace' => '', 'aliases' => []]);

        $dates = Gen::forParameters($function)['dates']->generate(new Random(5))->value;

        Assert::true(is_array($dates));

        foreach ($dates as $date) {
            Assert::instanceOf($date, \DateTimeImmutable::class);
        }
    }

    /**
     * Written at run time: the fixer drops a function import nothing calls.
     * A function member before the class, and a function group sharing the
     * class's short name, are both skipped — neither names a class.
     */
    public function functionImportsNeverShadowAClassImport(): void
    {
        $function = self::closureFromFile(<<<'PHP'
            <?php

            namespace Mixed;

            use Rasuvaeff\PropertyTesting\Tests\Support\{function helper, Priority};
            use function Elsewhere\{priority};

            return /** @param non-empty-list<Priority> $items */ static function (array $items): void {};
            PHP, unlink: false);

        try {
            Assert::same(DocblockTypes::imports($function)['aliases'], ['priority' => Priority::class]);

            $items = Gen::forParameters($function)['items']->generate(new Random(3))->value;

            Assert::true(is_array($items) && $items !== []);

            foreach ($items as $item) {
                Assert::instanceOf($item, Priority::class);
            }
        } finally {
            unlink((string) $function->getFileName());
        }
    }

    public function anUnreadableDocblockOverAMissingClassIsRefusedByTheDocblock(): void
    {
        $closure = /** @param Missing<int> $thing */ static function (\Rasuvaeff\PropertyTesting\Tests\Missing $thing): void {};

        try {
            Gen::forParameters(new \ReflectionFunction($closure));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('parameter $thing is typed Missing<int>, which this cannot read; pass an override');
        }
    }

    private static function closureFromFile(string $source, bool $unlink): \ReflectionFunction
    {
        $path = sprintf('%s/type-generators-%s.php', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        file_put_contents($path, $source);

        try {
            /** @var \Closure $closure */
            $closure = require $path;
        } finally {
            if ($unlink) {
                unlink($path);
            }
        }

        return new \ReflectionFunction($closure);
    }

    private static function read(string $type): ?ArbitraryInterface
    {
        return TypeGenerators::fromDocblock(
            $type,
            static fn(string $class): ArbitraryInterface => Gen::constant($class),
            static fn(string $name): ?string => null,
        );
    }

    /**
     * @return list<mixed>
     */
    private function draws(?ArbitraryInterface $arbitrary): array
    {
        Assert::true($arbitrary instanceof ArbitraryInterface);

        $random = new Random(11);
        $values = [];

        for ($i = 0; $i < self::DRAWS; ++$i) {
            $values[] = $arbitrary->generate($random)->value;
        }

        return $values;
    }
}
