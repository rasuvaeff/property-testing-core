<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\ValueCodec;
use Rasuvaeff\PropertyTesting\Tests\Support\Check;
use Rasuvaeff\PropertyTesting\Tests\Support\Priority;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ValueCodec::class)]
final class ValueCodecTest
{
    #[DataProvider('scalarProvider')]
    public function roundTripsScalars(mixed $value): void
    {
        Assert::same($this->roundTrip($value), $value);
    }

    public static function scalarProvider(): iterable
    {
        yield 'null' => [null];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'negative int' => [-42];
        yield 'int max' => [PHP_INT_MAX];
        yield 'int min' => [PHP_INT_MIN];
        yield 'float' => [1.5];
        yield 'float precision' => [0.1 + 0.2];
        yield 'tiny float' => [PHP_FLOAT_MIN];
        yield 'huge float' => [PHP_FLOAT_MAX];
        yield 'empty string' => [''];
        yield 'ascii string' => ['abc'];
        yield 'utf-8 string' => ['ключ ✓'];
        yield 'string with the envelope tag' => ['#'];
        yield 'newline' => ["a\nb"];
        yield 'nul byte inside utf-8' => ["a\0b"];
    }

    public function roundTripsNan(): void
    {
        $decoded = $this->roundTrip(NAN);

        Assert::true(is_float($decoded) && is_nan($decoded));
    }

    #[DataProvider('specialFloatProvider')]
    public function roundTripsSpecialFloats(float $value): void
    {
        Assert::same($this->roundTrip($value), $value);
    }

    public static function specialFloatProvider(): iterable
    {
        yield 'INF' => [INF];
        yield '-INF' => [-INF];
        yield 'zero' => [0.0];
    }

    public function roundTripsNegativeZeroWithItsSign(): void
    {
        $decoded = $this->roundTrip(-0.0);

        Assert::true(is_float($decoded) && $decoded === 0.0 && fdiv(1.0, $decoded) === -INF);
    }

    /**
     * `json_encode()` writes an integral float as an integer literal, so an
     * unenveloped float would come back as an int and replay a different input.
     */
    #[DataProvider('integralFloatProvider')]
    public function keepsIntegralFloatsFloatsAcrossJsonTransport(float $value): void
    {
        $decoded = $this->transport($value);

        Assert::true(is_float($decoded));
        Assert::same($decoded, $value);
    }

    public static function integralFloatProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'one' => [1.0];
        yield 'negative thousand' => [-1000.0];
        yield 'exponent' => [1.0E+25];
    }

    public function keepsFullPrecisionAcrossJsonTransport(): void
    {
        Assert::same($this->transport(0.1 + 0.2), 0.1 + 0.2);
        Assert::same($this->transport(PHP_FLOAT_EPSILON), PHP_FLOAT_EPSILON);
    }

    public function keepsNegativeZeroSignedAcrossJsonTransport(): void
    {
        $decoded = $this->transport(-0.0);

        Assert::true(is_float($decoded) && fdiv(1.0, $decoded) === -INF);
    }

    public function roundTripsBinaryStrings(): void
    {
        $bytes = "\x00\xff\xfe\x80binary";

        Assert::same($this->roundTrip($bytes), $bytes);
    }

    public function roundTripsEnumCases(): void
    {
        Assert::same($this->roundTrip(Priority::High), Priority::High);
    }

    #[DataProvider('arrayProvider')]
    public function roundTripsArrays(array $value): void
    {
        Assert::same($this->roundTrip($value), $value);
    }

    public static function arrayProvider(): iterable
    {
        yield 'empty' => [[]];
        yield 'list' => [[1, 2, 3]];
        yield 'string keys' => [['a' => 1, 'b' => 2]];
        yield 'mixed keys' => [[0 => 'zero', 'one' => 1, 7 => true]];
        yield 'nested' => [['a' => [1, ['b' => null]]]];
        yield 'numeric string key' => [['7' => 'as int per PHP casting']];
        yield 'gaps in integer keys' => [[3 => 'a', 1 => 'b']];
        yield 'special values inside' => [[INF, -0.0, "\xff"]];
        yield 'enum inside' => [['p' => Priority::Low]];
    }

    /**
     * PHP casts a decimal-integer string key to an int on insertion, so the
     * decoded array must carry an int key here — not the string that was encoded.
     */
    public function keepsIntegerKeysDistinctFromStringKeys(): void
    {
        /** @var array<array-key, mixed> $decoded */
        $decoded = $this->roundTrip([7 => 'int', 'x7' => 'string']);
        $keys = array_keys($decoded);

        Assert::same($keys, [7, 'x7']);
    }

    /**
     * The pair's integer-key flag wins over the literal text: a hand-edited corpus
     * file carrying a non-canonical numeric key must still decode to the integer
     * key the property was called with, not to a string key PHP would keep as-is.
     */
    public function integerKeyFlagCastsANonCanonicalNumericKey(): void
    {
        $decoded = ValueCodec::decode(['#' => 'a', 'p' => [['007', true, 'v']]]);

        \assert($decoded !== null);
        Assert::same($decoded[0], [7 => 'v']);
    }

    public function pairsThatAreNotThreeElementListsAreRefused(): void
    {
        Assert::null(ValueCodec::decode(['#' => 'a', 'p' => [['k', false, 'v', 'extra']]]));
        Assert::null(ValueCodec::decode(['#' => 'a', 'p' => [[1 => 'k', 2 => false, 3 => 'v']]]));
    }

    #[DataProvider('unsupportedProvider')]
    public function refusesValuesWithNoDataRepresentation(mixed $value): void
    {
        Assert::null(ValueCodec::encode($value));
    }

    public static function unsupportedProvider(): iterable
    {
        yield 'object' => [new \stdClass()];
        yield 'date' => [new \DateTimeImmutable('2026-01-01')];
        yield 'closure' => [static fn(): int => 1];
        yield 'object nested in an array' => [['a' => new \stdClass()]];
        yield 'object nested deeper' => [[[['x' => new \stdClass()]]]];
    }

    public function encodeWrapsSoNullIsDistinguishableFromRefusal(): void
    {
        Assert::same(ValueCodec::encode(null), [null]);
        Assert::null(ValueCodec::encode(new \stdClass()));
    }

    #[DataProvider('corruptProvider')]
    public function decodeRefusesCorruptPayloads(mixed $payload): void
    {
        Assert::null(ValueCodec::decode($payload));
    }

    public static function corruptProvider(): iterable
    {
        yield 'array without a tag' => [['v' => 1]];
        yield 'non-string tag' => [['#' => 7]];
        yield 'unknown tag' => [['#' => 'zz']];
        yield 'array envelope without pairs' => [['#' => 'a']];
        yield 'array envelope with a non-array pairs field' => [['#' => 'a', 'p' => 'nope']];
        yield 'array pair that is not an array' => [['#' => 'a', 'p' => ['nope']]];
        yield 'array pair missing its value' => [['#' => 'a', 'p' => [['k', false]]]];
        yield 'array pair with a non-bool int flag' => [['#' => 'a', 'p' => [['k', 'yes', 1]]]];
        yield 'array pair with a non-string key' => [['#' => 'a', 'p' => [[['#' => 'a', 'p' => []], false, 1]]]];
        yield 'array pair with an undecodable value' => [['#' => 'a', 'p' => [['k', false, ['#' => 'zz']]]]];
        yield 'float envelope without a value' => [['#' => 'f']];
        yield 'float envelope with an unknown value' => [['#' => 'f', 'v' => 'PI']];
        // The non-finite tokens are lower-case on purpose: var_export() emits
        // upper-case NAN/INF (with a warning for NAN), and keeping the two apart
        // means only a token this codec wrote is ever accepted.
        yield 'float envelope with an upper-case token' => [['#' => 'f', 'v' => 'NAN']];
        yield 'float envelope with an upper-case infinity token' => [['#' => 'f', 'v' => 'INF']];
        yield 'bytes envelope without a value' => [['#' => 'b']];
        yield 'bytes envelope with non-base64' => [['#' => 'b', 'v' => '!!!not base64!!!']];
        yield 'enum envelope without a class' => [['#' => 'e', 'n' => 'High']];
        yield 'enum envelope with a non-enum class' => [['#' => 'e', 'c' => \stdClass::class, 'n' => 'High']];
        yield 'enum envelope with an unknown case' => [['#' => 'e', 'c' => Priority::class, 'n' => 'Nope']];
        yield 'enum envelope with a non-string case' => [['#' => 'e', 'c' => Priority::class, 'n' => 7]];
        // Not an array at all: the guard must refuse it with null, never reach
        // the tag lookup (an object offset access would be a fatal Error).
        yield 'bare object' => [new \stdClass()];
    }

    /**
     * The encoded form must survive a real JSON round trip — that is the only
     * form {@see \Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus} stores.
     */
    public function encodedValuesSurviveJsonTransport(): void
    {
        // Gen::json() is exactly the heterogeneous, arbitrarily nested domain the
        // codec must carry: null/bool/int/float/string plus lists and maps.
        Check::property(
            function (mixed $value): void {
                Assert::same($this->transport($value), $value);
            },
            ['value' => Gen::json(3)],
            runs: 200,
            seed: 20260730,
        );
    }

    public function encodedByteStringsSurviveJsonTransport(): void
    {
        Check::property(
            function (string $value): void {
                Assert::same($this->transport($value), $value);
            },
            ['value' => Gen::bytes(0, 12)],
            runs: 100,
            seed: 20260731,
        );
    }

    public function refusesAFiniteFloatThatDoesNotSurviveSerializePrecision(): void
    {
        $previous = ini_get('serialize_precision');
        \assert(is_string($previous));

        // At precision 3 var_export(M_PI) emits "3.14", which decodes to a
        // different float — the codec must refuse so the corpus falls back to
        // the seed entry instead of silently replaying the wrong value.
        ini_set('serialize_precision', '3');

        try {
            Assert::null(ValueCodec::encode(M_PI));
            Assert::same($this->roundTrip(0.5), 0.5);
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    private function roundTrip(mixed $value): mixed
    {
        $encoded = ValueCodec::encode($value);
        \assert($encoded !== null);

        $decoded = ValueCodec::decode($encoded[0]);
        \assert($decoded !== null);

        return $decoded[0];
    }

    private function transport(mixed $value): mixed
    {
        $encoded = ValueCodec::encode($value);
        \assert($encoded !== null);

        /** @var mixed $transported */
        $transported = json_decode(
            json_encode($encoded[0], JSON_THROW_ON_ERROR),
            associative: true,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $decoded = ValueCodec::decode($transported);
        \assert($decoded !== null);

        return $decoded[0];
    }
}
