<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\Arbitrary\ArrayArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\BoolArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\BytesArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\CharsetStringArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\DateTimeArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\DictionaryArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FilteredArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FlatMappedArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FloatArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FrequencyArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\MappedArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\NullableArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\OneOfArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\RecordArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\StringArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\SwarmArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\TupleArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\UniqueArrayArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\UuidArbitrary;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\PushCommand;
use Rasuvaeff\PropertyTesting\Tests\Support\Priority;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Gen::class)]
final class GenTest
{
    public function intReturnsUnboundedIntArbitrary(): void
    {
        Assert::instanceOf(Gen::int(), IntArbitrary::class);
    }

    public function intPositiveStartsAtOne(): void
    {
        Assert::instanceOf(Gen::intPositive(), IntArbitrary::class);
    }

    public function intBetweenAcceptsCustomBounds(): void
    {
        $arbitrary = Gen::intBetween(-5, 5);

        Assert::instanceOf($arbitrary, IntArbitrary::class);
        Assert::same($arbitrary->generate(new Random(1))->value >= -5, expected: true);
    }

    public function floatAndFloatBetweenReturnFloatArbitrary(): void
    {
        Assert::instanceOf(Gen::float(), FloatArbitrary::class);
        Assert::instanceOf(Gen::floatBetween(0.0, 10.0), FloatArbitrary::class);
    }

    public function boolReturnsBoolArbitrary(): void
    {
        Assert::instanceOf(Gen::bool(), BoolArbitrary::class);
    }

    public function stringFactoriesReturnStringArbitrary(): void
    {
        Assert::instanceOf(Gen::string(), StringArbitrary::class);
        Assert::instanceOf(Gen::stringAscii(), StringArbitrary::class);
        Assert::instanceOf(Gen::stringOf(2, 8), StringArbitrary::class);
    }

    public function arrayFactoriesReturnArrayArbitrary(): void
    {
        Assert::instanceOf(Gen::arrayOf(Gen::int()), ArrayArbitrary::class);
        Assert::instanceOf(Gen::nonEmptyArrayOf(Gen::int()), ArrayArbitrary::class);
    }

    public function dictOfReturnsDictionaryArbitrary(): void
    {
        Assert::instanceOf(Gen::dictOf(Gen::stringOf(1, 5), Gen::int()), DictionaryArbitrary::class);
    }

    public function ipv4GeneratesDottedQuads(): void
    {
        foreach (Gen::sample(Gen::ipv4(), 30, 3) as $value) {
            Assert::true(is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
        }
    }

    public function ipv6GeneratesCanonicalAddresses(): void
    {
        foreach (Gen::sample(Gen::ipv6(), 50, 3) as $value) {
            Assert::true(is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);
            \assert(is_string($value));

            // The RFC 5952 form is exactly what inet_ntop emits, so a canonical
            // address survives a parse/render round trip unchanged.
            Assert::same(inet_ntop((string) inet_pton($value)), $value);
        }
    }

    public function emailGeneratesValidAddresses(): void
    {
        foreach (Gen::sample(Gen::email(), 30, 3) as $value) {
            Assert::true(is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false);
        }
    }

    public function urlGeneratesValidHttpUrls(): void
    {
        foreach (Gen::sample(Gen::url(), 30, 3) as $value) {
            Assert::true(is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false);
        }
    }

    public function jsonGeneratesEncodableValues(): void
    {
        foreach (Gen::sample(Gen::json(), 30, 3) as $value) {
            Assert::true(json_encode($value) !== false);
        }
    }

    public function jsonStringGeneratesParseableJson(): void
    {
        foreach (Gen::sample(Gen::jsonString(), 30, 3) as $value) {
            Assert::true(is_string($value));
            \assert(is_string($value));
            json_decode($value, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        }
    }

    public function regexAndStringMatchingGenerateMatchingStrings(): void
    {
        foreach (['[a-z]{3}', '\\d+', 'foo|bar'] as $pattern) {
            foreach (Gen::sample(Gen::stringMatching($pattern), 20, 3) as $value) {
                Assert::true(is_string($value) && preg_match('/^(?:' . $pattern . ')$/', $value) === 1);
            }
        }
    }

    public function ipv4OctetsSpanTheFullRange(): void
    {
        $octets = [];
        foreach (Gen::sample(Gen::ipv4(), 200, 4) as $ip) {
            if (is_string($ip)) {
                foreach (explode('.', $ip) as $octet) {
                    $octets[] = (int) $octet;
                }
            }
        }

        Assert::true(in_array(0, $octets, strict: true));
        Assert::true(in_array(255, $octets, strict: true));
    }

    public function ipv6GroupsSpanTheFullRangeAndReachTheCompressedForm(): void
    {
        $groups = [];
        $compressed = 0;
        foreach (Gen::sample(Gen::ipv6(), 400, 4) as $address) {
            \assert(is_string($address));
            $compressed += str_contains($address, '::') ? 1 : 0;

            foreach ((array) unpack('n8', (string) inet_pton($address)) as $group) {
                $groups[] = $group;
            }
        }

        Assert::true(in_array(0, $groups, strict: true));
        Assert::true(in_array(65535, $groups, strict: true));
        // Zero groups come from the boundary bias, so the shortened form shows
        // up on its own — rarely (single-digit percent), but it does.
        Assert::true($compressed > 0);
    }

    public function ipv6ShrinksTowardTheAllZeroAddress(): void
    {
        $node = Trees::generateWhere(Gen::ipv6(), static fn(mixed $v): bool => $v !== '::');

        // Greedy descent through the first candidate at every level: each group
        // is driven to zero in turn, so the tree bottoms out at the all-zero
        // address, which the canonical form renders as "::".
        for ($step = 0; $step < 100; ++$step) {
            $first = null;
            foreach ($node->shrinks() as $candidate) {
                $first = $candidate;

                break;
            }

            if ($first === null) {
                break;
            }

            $node = $first;
        }

        Assert::same($node->value, '::');
    }

    public function jsonProducesEveryLeafTypeAndNesting(): void
    {
        $values = Gen::sample(Gen::json(), 400, 4);

        Assert::true($this->anySatisfies($values, static fn(mixed $v): bool => $v === null));
        Assert::true($this->anySatisfies($values, is_bool(...)));
        Assert::true($this->anySatisfies($values, is_int(...)));
        Assert::true($this->anySatisfies($values, is_float(...)));
        Assert::true($this->anySatisfies($values, is_string(...)));
        Assert::true($this->anySatisfies($values, is_array(...)));
    }

    public function jsonDictionaryKeysSpanLengthOneToEight(): void
    {
        // Pins the dictionary-key generator's exact length bounds (1..8): over
        // ~2000 sampled keys both extremes appear and nothing falls outside.
        $stats = $this->jsonStats();

        Assert::same($stats['keyLenMin'], 1);
        Assert::same($stats['keyLenMax'], 8);
    }

    public function jsonBalancesListAndDictionaryBranchesEqually(): void
    {
        // The container-level frequency pair is 1:1, so non-empty containers
        // split ~50/50 between lists and dictionaries (893 vs 843 for this
        // seed); doubling one weight would push the split to ~2/3.
        $stats = $this->jsonStats();

        $share = $stats['lists'] / ($stats['lists'] + $stats['dicts']);
        Assert::true($share > 0.44 && $share < 0.56);
    }

    public function jsonDictionariesSpanSizeZeroToFour(): void
    {
        // Dictionary sizes are drawn from [0, 4]: size 4 occurs (~200 times
        // for this seed) and nothing above. Empty containers keep their full
        // ~1/5 share (459 of 2195) — a dictionary minimum of 1 would halve it,
        // because only the list branch could still produce [].
        $stats = $this->jsonStats();

        Assert::same($stats['dictSizeMax'], 4);

        $emptyShare = $stats['empty'] / $stats['arrays'];
        Assert::true($emptyShare > 0.15 && $emptyShare < 0.26);
    }

    /**
     * Walks 1500 seeded {@see Gen::json()} samples and aggregates container
     * statistics. Lists and dictionaries are told apart by their keys, so only
     * non-empty containers are classified ([] is ambiguous).
     *
     * @return array{arrays: int, empty: int, lists: int, dicts: int, dictSizeMax: int, keyLenMin: int, keyLenMax: int}
     */
    private function jsonStats(): array
    {
        $stats = [
            'arrays' => 0,
            'empty' => 0,
            'lists' => 0,
            'dicts' => 0,
            'dictSizeMax' => 0,
            'keyLenMin' => PHP_INT_MAX,
            'keyLenMax' => 0,
        ];

        $walk = static function (mixed $value) use (&$walk, &$stats): void {
            if (!is_array($value)) {
                return;
            }

            ++$stats['arrays'];

            if ($value === []) {
                ++$stats['empty'];
            } elseif (array_is_list($value)) {
                ++$stats['lists'];
            } else {
                ++$stats['dicts'];
                $stats['dictSizeMax'] = max($stats['dictSizeMax'], count($value));
            }

            /** @var mixed $inner */
            foreach ($value as $key => $inner) {
                if (is_string($key)) {
                    $stats['keyLenMin'] = min($stats['keyLenMin'], strlen($key));
                    $stats['keyLenMax'] = max($stats['keyLenMax'], strlen($key));
                }

                $walk($inner);
            }
        };

        foreach (Gen::sample(Gen::json(), 1500, 1) as $value) {
            $walk($value);
        }

        return $stats;
    }

    /**
     * @param list<mixed> $values
     * @param callable(mixed): bool $predicate
     */
    private function anySatisfies(array $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if ($predicate($value)) {
                return true;
            }
        }

        return false;
    }

    public function arrayOfAcceptsCustomSizeBounds(): void
    {
        $arbitrary = Gen::arrayOf(Gen::int(), 2, 5);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $size = count($arbitrary->generate($random)->value);

            Assert::true($size >= 2 && $size <= 5);
        }
    }

    public function nonEmptyArrayOfAcceptsACustomMaximumSize(): void
    {
        $arbitrary = Gen::nonEmptyArrayOf(Gen::int(), 3);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $size = count($arbitrary->generate($random)->value);

            Assert::true($size >= 1 && $size <= 3);
        }
    }

    public function dictOfAcceptsCustomSizeBounds(): void
    {
        $arbitrary = Gen::dictOf(Gen::stringOf(20, 20), Gen::int(), 2, 4);
        $random = new Random(1);

        for ($i = 0; $i < 100; ++$i) {
            $size = count($arbitrary->generate($random)->value);

            Assert::true($size >= 2 && $size <= 4);
        }
    }

    public function uniqueArrayOfReturnsUniqueArrayArbitrary(): void
    {
        $arbitrary = Gen::uniqueArrayOf(Gen::intBetween(0, 1000), 1, 5);

        Assert::instanceOf($arbitrary, UniqueArrayArbitrary::class);

        $value = $arbitrary->generate(new Random(1))->value;
        Assert::same(count($value) >= 1 && count($value) <= 5, expected: true);
    }

    public function stringFromReturnsCharsetStringArbitrary(): void
    {
        $arbitrary = Gen::stringFrom('0123456789abcdef', 1, 8);

        Assert::instanceOf($arbitrary, CharsetStringArbitrary::class);
        Assert::same(preg_match('/^[0-9a-f]{1,8}$/', (string) $arbitrary->generate(new Random(1))->value), 1);
    }

    public function bytesReturnsBytesArbitrary(): void
    {
        $arbitrary = Gen::bytes(4, 4);

        Assert::instanceOf($arbitrary, BytesArbitrary::class);
        Assert::same(strlen((string) $arbitrary->generate(new Random(1))->value), 4);
    }

    public function enumPicksOnlyDeclaredCases(): void
    {
        $arbitrary = Gen::enum(Priority::class);

        Assert::instanceOf($arbitrary, OneOfArbitrary::class);

        $random = new Random(1);
        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $case = $arbitrary->generate($random)->value;

            Assert::instanceOf($case, Priority::class);
            $seen[$case->name] = true;
        }

        Assert::same(isset($seen['Low'], $seen['Medium'], $seen['High']), expected: true);
    }

    public function enumShrinksTowardEarlierDeclaredCases(): void
    {
        $node = Trees::generateWhere(Gen::enum(Priority::class), static fn(mixed $v): bool => $v === Priority::High);

        Assert::same(Trees::childValues($node), [Priority::Low, Priority::Medium]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function enumRejectsANonEnumClass(): void
    {
        Gen::enum(\stdClass::class);
    }

    public function floatSpecialCoversTheSpecialValues(): void
    {
        $arbitrary = Gen::floatSpecial();
        $random = new Random(1);
        $sawNan = false;
        $sawInf = false;
        $sawNegativeInf = false;

        $sawNegativeZero = false;

        for ($i = 0; $i < 300; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true(is_float($value));
            $sawNan = $sawNan || is_nan($value);
            $sawInf = $sawInf || $value === INF;
            $sawNegativeInf = $sawNegativeInf || $value === -INF;
            // -0.0 === 0.0 in PHP; the sign is only observable via division.
            $sawNegativeZero = $sawNegativeZero || ($value === 0.0 && fdiv(1.0, $value) === -INF);
        }

        Assert::true($sawNan);
        Assert::true($sawInf);
        Assert::true($sawNegativeInf);
        Assert::true($sawNegativeZero);
    }

    public function intRangeGeneratesOrderedPairsWithinBounds(): void
    {
        $arbitrary = Gen::intRange(-50, 50);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            [$lo, $hi] = $arbitrary->generate($random)->value;

            Assert::true($lo >= -50 && $lo <= $hi && $hi <= 50);
        }
    }

    public function intRangeShrinkKeepsThePairOrdered(): void
    {
        $node = Trees::generateWhere(
            Gen::intRange(0, 100),
            static fn(mixed $v): bool => is_array($v) && $v[0] >= 10 && $v[1] >= $v[0] + 10,
        );

        foreach (Trees::valuesToDepth($node, 3) as [$lo, $hi]) {
            Assert::true($lo >= 0 && $lo <= $hi && $hi <= 100);
        }
    }

    public function recursiveNestsUpToTheMaximumDepth(): void
    {
        $arbitrary = Gen::recursive(
            Gen::intBetween(0, 9),
            static fn(ArbitraryInterface $inner): ArbitraryInterface => new ArrayArbitrary($inner, 1, 3),
            maxDepth: 3,
        );
        $random = new Random(1);
        $maxDepth = 0;

        for ($i = 0; $i < 300; ++$i) {
            /** @var mixed $value */
            $value = $arbitrary->generate($random)->value;
            $maxDepth = max($maxDepth, self::depth($value));
        }

        // Nesting happens (the wrap branch is taken) but never beyond maxDepth.
        Assert::true($maxDepth >= 2 && $maxDepth <= 3);
    }

    public function recursiveAcceptsADepthOfOne(): void
    {
        // maxDepth === 1 is the boundary: exactly one leaf-or-branch choice.
        $arbitrary = Gen::recursive(
            Gen::constant(0),
            static fn(ArbitraryInterface $inner): ArbitraryInterface => new ArrayArbitrary($inner, 1, 1),
            maxDepth: 1,
        );
        $random = new Random(1);

        for ($i = 0; $i < 50; ++$i) {
            /** @var mixed $value */
            $value = $arbitrary->generate($random)->value;

            Assert::true($value === 0 || $value === [0]);
        }
    }

    public function recursivePicksLeafAndBranchWithEqualOdds(): void
    {
        // The top level chooses leaf vs wrapped 1:1 (~300 of 600 leaves); a
        // skewed weight pair or a dropped pair would leave the band.
        $arbitrary = Gen::recursive(
            Gen::constant(0),
            static fn(ArbitraryInterface $inner): ArbitraryInterface => new ArrayArbitrary($inner, 1, 1),
            maxDepth: 1,
        );
        $random = new Random(1);
        $leaves = 0;

        for ($i = 0; $i < 600; ++$i) {
            if ($arbitrary->generate($random)->value === 0) {
                ++$leaves;
            }
        }

        Assert::true($leaves > 240 && $leaves < 360);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function recursiveRejectsAZeroDepth(): void
    {
        Gen::recursive(Gen::int(), static fn(ArbitraryInterface $inner): ArbitraryInterface => Gen::arrayOf($inner), 0);
    }

    public function recursiveRejectsAWrapClosureNotReturningAnArbitrary(): void
    {
        // The recursive() guard must fire itself (with its own message), not
        // fall through to FrequencyArbitrary's pair validation.
        try {
            Gen::recursive(Gen::int(), static fn(ArbitraryInterface $inner): int => 42);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('wrap closure');
        }
    }

    private static function depth(mixed $value): int
    {
        if (!is_array($value)) {
            return 0;
        }

        $max = 0;
        /** @var mixed $element */
        foreach ($value as $element) {
            $max = max($max, self::depth($element));
        }

        return 1 + $max;
    }

    public function dictOfReachesTheEmptyMap(): void
    {
        // The factory must configure minSize 0 so an empty map is reachable.
        $arbitrary = Gen::dictOf(Gen::stringOf(1, 5), Gen::int());
        $random = new Random(1);
        $sawEmpty = false;

        for ($i = 0; $i < 200; ++$i) {
            if ($arbitrary->generate($random)->value === []) {
                $sawEmpty = true;

                break;
            }
        }

        Assert::true($sawEmpty);
    }

    public function dictOfSpansSizeZeroToHundred(): void
    {
        // Long random string keys make collisions vanishingly unlikely, so the
        // observed maximum size pins the factory's exact upper bound.
        $arbitrary = Gen::dictOf(Gen::stringOf(20, 20), Gen::int());
        $random = new Random(1);
        $maxSize = 0;

        for ($i = 0; $i < 2000; ++$i) {
            $maxSize = max($maxSize, count($arbitrary->generate($random)->value));
        }

        Assert::same($maxSize, 100);
    }

    public function recordReturnsRecordArbitrary(): void
    {
        Assert::instanceOf(Gen::record(['x' => Gen::int(), 'y' => Gen::bool()]), RecordArbitrary::class);
    }

    public function oneOfReturnsOneOfArbitrary(): void
    {
        Assert::instanceOf(Gen::oneOf('a', 'b', 'c'), OneOfArbitrary::class);
    }

    public function elementsReturnsOneOfArbitraryFromAnArray(): void
    {
        $arbitrary = Gen::elements(['a', 'b', 'c']);

        Assert::instanceOf($arbitrary, OneOfArbitrary::class);
        Assert::true(in_array($arbitrary->generate(new Random(1))->value, ['a', 'b', 'c'], strict: true));
    }

    public function elementsAcceptsAStringKeyedArray(): void
    {
        // Keys are dropped via array_values, so a non-list array is accepted.
        $arbitrary = Gen::elements(['x' => 10, 'y' => 20]);

        Assert::true(in_array($arbitrary->generate(new Random(1))->value, [10, 20], strict: true));
    }

    public function constantReturnsConstantArbitrary(): void
    {
        $arbitrary = Gen::constant('fixed');

        Assert::instanceOf($arbitrary, ConstantArbitrary::class);
        Assert::same($arbitrary->generate(new Random(1))->value, 'fixed');
    }

    public function charReturnsSinglePrintableAsciiCharacter(): void
    {
        $arbitrary = Gen::char();

        Assert::instanceOf($arbitrary, StringArbitrary::class);

        $random = new Random(1);
        for ($i = 0; $i < 100; ++$i) {
            $char = $arbitrary->generate($random)->value;
            $code = ord($char);

            Assert::same(strlen((string) $char), 1);
            Assert::true($code >= 32 && $code <= 126);
        }
    }

    public function uuidReturnsUuidArbitrary(): void
    {
        Assert::instanceOf(Gen::uuid(), UuidArbitrary::class);
    }

    public function datetimeReturnsDateTimeArbitrary(): void
    {
        Assert::instanceOf(Gen::datetime(), DateTimeArbitrary::class);
        Assert::instanceOf(
            Gen::datetime(new DateTimeImmutable('@0'), new DateTimeImmutable('@100')),
            DateTimeArbitrary::class,
        );
    }

    public function sampleGeneratesTheRequestedNumberOfValues(): void
    {
        $values = Gen::sample(Gen::intBetween(1, 6), 20, 42);

        Assert::same(count($values), 20);

        foreach ($values as $value) {
            Assert::true(is_int($value) && $value >= 1 && $value <= 6);
        }
    }

    public function sampleIsReproducibleForAGivenSeed(): void
    {
        Assert::same(
            Gen::sample(Gen::intBetween(1, 1_000_000), 10, 7),
            Gen::sample(Gen::intBetween(1, 1_000_000), 10, 7),
        );
    }

    public function sampleReturnsPlainValuesNotShrinkables(): void
    {
        // sample() unwraps each Shrinkable — consumers get values, not trees.
        foreach (Gen::sample(Gen::intBetween(1, 6), 5, 42) as $value) {
            Assert::true(is_int($value));
        }
    }

    public function sampleAcceptsACountOfOne(): void
    {
        Assert::same(count(Gen::sample(Gen::int(), 1, 0)), 1);
    }

    public function sampleShrinksExposesTheValueAndItsFirstCandidates(): void
    {
        // Scan seeds for a non-zero value so the ladder is non-empty; the first
        // candidate of an int is always the in-range target.
        $seed = 0;
        while (Gen::sample(Gen::intBetween(0, 100), 1, $seed)[0] === 0) {
            ++$seed;

            if ($seed > 100) {
                Assert::fail('no seed in [0, 100] produced a non-zero value');
            }
        }

        $sampled = Gen::sampleShrinks(Gen::intBetween(0, 100), seed: $seed);

        Assert::true($sampled['value'] !== 0);
        Assert::same($sampled['shrinks'][0], 0);
    }

    public function sampleShrinksMatchesTheGeneratedValueForTheSeed(): void
    {
        Assert::same(
            Gen::sampleShrinks(Gen::intBetween(0, 1000), seed: 7)['value'],
            Gen::sample(Gen::intBetween(0, 1000), 1, 7)[0],
        );
    }

    public function sampleShrinksRespectsTheLimit(): void
    {
        // A long string offers far more than two candidates; the cap wins.
        $sampled = Gen::sampleShrinks(Gen::stringOf(20, 20), seed: 1, limit: 2);

        Assert::same(count($sampled['shrinks']), 2);
    }

    public function sampleShrinksAcceptsALimitOfOne(): void
    {
        // limit === 1 is the boundary of the "at least 1" rule.
        Assert::same(count(Gen::sampleShrinks(Gen::stringOf(20, 20), seed: 1, limit: 1)['shrinks']), 1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function sampleShrinksRejectsNonPositiveLimit(): void
    {
        Gen::sampleShrinks(Gen::int(), seed: 1, limit: 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function sampleRejectsNonPositiveCount(): void
    {
        Gen::sample(Gen::int(), 0);
    }

    public function nullableWrapsInnerArbitrary(): void
    {
        Assert::instanceOf(Gen::nullable(Gen::int()), NullableArbitrary::class);
    }

    public function mapReturnsMappedArbitrary(): void
    {
        Assert::instanceOf(Gen::map(Gen::int(), static fn(int $x): int => $x * 2), MappedArbitrary::class);
    }

    public function flatMapReturnsFlatMappedArbitrary(): void
    {
        $arbitrary = Gen::flatMap(Gen::intBetween(1, 10), static fn(int $n): IntArbitrary => new IntArbitrary(0, $n));

        Assert::instanceOf($arbitrary, FlatMappedArbitrary::class);
    }

    public function flatMapGeneratesDependentValues(): void
    {
        $arbitrary = Gen::flatMap(
            Gen::intBetween(1, 10),
            static fn(int $n): TupleArbitrary => new TupleArbitrary(new ConstantArbitrary($n), new IntArbitrary(0, $n - 1)),
        );
        $random = new Random(1);

        for ($i = 0; $i < 100; ++$i) {
            [$n, $index] = $arbitrary->generate($random)->value;

            Assert::true($index >= 0 && $index < $n);
        }
    }

    public function filterReturnsFilteredArbitrary(): void
    {
        Assert::instanceOf(Gen::filter(Gen::int(), static fn(int $x): bool => $x > 0), FilteredArbitrary::class);
    }

    public function tupleReturnsTupleArbitrary(): void
    {
        Assert::instanceOf(Gen::tuple(Gen::int(), Gen::bool()), TupleArbitrary::class);
    }

    public function frequencyReturnsFrequencyArbitrary(): void
    {
        Assert::instanceOf(Gen::frequency([[3, Gen::int()], [1, Gen::bool()]]), FrequencyArbitrary::class);
    }

    public function swarmReturnsSwarmArbitraryOverEveryChoiceGenerator(): void
    {
        Assert::instanceOf(Gen::swarm(Gen::oneOf('a', 'b')), SwarmArbitrary::class);
        Assert::instanceOf(Gen::swarm(Gen::elements(['a', 'b'])), SwarmArbitrary::class);
        Assert::instanceOf(Gen::swarm(Gen::frequency([[1, Gen::int()], [1, Gen::bool()]])), SwarmArbitrary::class);
        Assert::instanceOf(
            Gen::swarm(Gen::commands([], [Gen::constant(new PushCommand(1))])),
            SwarmArbitrary::class,
        );
    }

    public function intPositiveHasLowerBoundOne(): void
    {
        // The clamped shrink target reveals the configured lower bound.
        $node = Trees::generateWhere(Gen::intPositive(), static fn(mixed $v): bool => $v !== 1);

        Assert::same(Trees::childValues($node)[0], 1);
    }

    public function floatSpansTheHalfOpenUnitRange(): void
    {
        $random = new Random(7);
        $sawLow = false;
        $sawHigh = false;

        for ($i = 0; $i < 200; ++$i) {
            $value = Gen::float()->generate($random)->value;

            Assert::true($value >= 0.0 && $value < 1.0);
            $value < 0.5 ? $sawLow = true : $sawHigh = true;
        }

        Assert::true($sawLow);
        Assert::true($sawHigh);
    }

    public function stringIsUnicodeAndSpansLengthZeroToHundred(): void
    {
        $random = new Random(1);
        $sawEmpty = false;
        $sawMultibyte = false;
        $maxLength = 0;

        for ($i = 0; $i < 4000; ++$i) {
            $value = Gen::string()->generate($random)->value;
            $length = mb_strlen((string) $value, 'UTF-8');
            $maxLength = max($maxLength, $length);

            if ($value === '') {
                $sawEmpty = true;
            }
            if (strlen((string) $value) !== $length) {
                $sawMultibyte = true;
            }
        }

        Assert::true($sawEmpty);
        Assert::true($sawMultibyte);
        Assert::same($maxLength, 100);
    }

    public function stringAsciiIsPrintableAsciiAndSpansLengthZeroToHundred(): void
    {
        $random = new Random(1);
        $sawEmpty = false;
        $maxLength = 0;

        for ($i = 0; $i < 4000; ++$i) {
            $value = Gen::stringAscii()->generate($random)->value;
            $maxLength = max($maxLength, strlen((string) $value));

            if ($value === '') {
                $sawEmpty = true;
            }
            foreach (str_split($value === '' ? ' ' : $value) as $char) {
                Assert::true(ord($char) >= 32 && ord($char) <= 126);
            }
        }

        Assert::true($sawEmpty);
        Assert::same($maxLength, 100);
    }

    public function stringOfIsUnicodeAndRespectsBounds(): void
    {
        $random = new Random(1);
        $sawMultibyte = false;

        for ($i = 0; $i < 500; ++$i) {
            $value = Gen::stringOf(3, 6)->generate($random)->value;
            $length = mb_strlen((string) $value, 'UTF-8');

            Assert::true($length >= 3 && $length <= 6);
            if (strlen((string) $value) !== $length) {
                $sawMultibyte = true;
            }
        }

        Assert::true($sawMultibyte);
    }

    public function arrayOfSpansSizeZeroToHundred(): void
    {
        $random = new Random(1);
        $sawEmpty = false;
        $maxSize = 0;

        for ($i = 0; $i < 4000; ++$i) {
            $size = count(Gen::arrayOf(Gen::int())->generate($random)->value);
            $maxSize = max($maxSize, $size);

            if ($size === 0) {
                $sawEmpty = true;
            }
        }

        Assert::true($sawEmpty);
        Assert::same($maxSize, 100);
    }

    public function nonEmptyArrayOfSpansSizeOneToHundred(): void
    {
        $random = new Random(1);
        $minSize = PHP_INT_MAX;
        $maxSize = 0;

        for ($i = 0; $i < 4000; ++$i) {
            $size = count(Gen::nonEmptyArrayOf(Gen::int())->generate($random)->value);
            $minSize = min($minSize, $size);
            $maxSize = max($maxSize, $size);
        }

        Assert::same($minSize, 1);
        Assert::same($maxSize, 100);
    }

    #[ExpectException(\RuntimeException::class)]
    public function drawOutsideAPropertyRunThrows(): void
    {
        Gen::draw(Gen::int());
    }
}
