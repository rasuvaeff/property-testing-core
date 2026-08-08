<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

/**
 * Lossless JSON codec for counterexample values, used by {@see FilesystemCorpus} to
 * persist a minimised failing input as data rather than as a seed.
 *
 * A stored value must replay to a value *identical* to the one that failed —
 * otherwise the regression replay silently tests something else. Everything JSON
 * cannot represent exactly travels in a tagged envelope: floats (as text, so an
 * integral one stays a float), binary strings, enum cases, and arrays (as an
 * ordered list of key/value pairs, so integer keys and their order survive the
 * round trip).
 *
 * Anything with no data-only representation — objects other than enums,
 * resources, closures — is refused: {@see encode()} returns null and the caller
 * falls back to storing the run's seed.
 *
 * @internal
 */
final readonly class ValueCodec
{
    private const string TAG = '#';
    private const string TAG_ARRAY = 'a';
    private const string TAG_FLOAT = 'f';
    private const string TAG_BYTES = 'b';
    private const string TAG_ENUM = 'e';
    private const string FLOAT_NAN = 'nan';
    private const string FLOAT_INF = 'inf';
    private const string FLOAT_NEGATIVE_INF = '-inf';

    /**
     * The JSON-safe representation of $value wrapped in a single-element list,
     * or null when the value has no data-only representation.
     *
     * The wrapper distinguishes "encoded to null" from "cannot encode".
     *
     * @return ?array{0: mixed}
     */
    public static function encode(mixed $value): ?array
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return [$value];
        }

        if (is_float($value)) {
            return [self::encodeFloat($value)];
        }

        if (is_string($value)) {
            return [self::encodeString($value)];
        }

        if (is_array($value)) {
            return self::encodeArray($value);
        }

        if ($value instanceof \UnitEnum) {
            return [[self::TAG => self::TAG_ENUM, 'c' => $value::class, 'n' => $value->name]];
        }

        return null;
    }

    /**
     * Rebuilds the value encoded by {@see encode()}, wrapped the same way, or
     * null when the payload is not a valid encoding (a corrupt or
     * forward-version corpus file).
     *
     * @return ?array{0: mixed}
     */
    public static function decode(mixed $encoded): ?array
    {
        if ($encoded === null || is_bool($encoded) || is_int($encoded) || is_float($encoded) || is_string($encoded)) {
            return [$encoded];
        }

        if (!is_array($encoded) || !isset($encoded[self::TAG]) || !is_string($encoded[self::TAG])) {
            return null;
        }

        return match ($encoded[self::TAG]) {
            self::TAG_ARRAY => self::decodeArray($encoded),
            self::TAG_FLOAT => self::decodeFloat($encoded),
            self::TAG_BYTES => self::decodeBytes($encoded),
            self::TAG_ENUM => self::decodeEnum($encoded),
            default => null,
        };
    }

    /**
     * Floats always travel tagged, as text. JSON cannot express NAN/±INF at all,
     * and `json_encode()` renders an integral float as an integer literal
     * (`0.0` -> `0`, `-1000.0` -> `-1000`) which would decode back as an int and
     * replay a different input. `var_export()` writes a representation that casts
     * back to the same float, negative zero included.
     *
     * The non-finite values get lower-case tokens of their own: `var_export()`
     * emits a PHP warning for NAN, and a token no numeric literal and no
     * `var_export()` output can collide with keeps the decoder unambiguous.
     *
     * @return array<string, string>
     */
    private static function encodeFloat(float $value): array
    {
        $text = match (true) {
            is_nan($value) => self::FLOAT_NAN,
            $value === INF => self::FLOAT_INF,
            $value === -INF => self::FLOAT_NEGATIVE_INF,
            default => var_export($value, return: true),
        };

        return [self::TAG => self::TAG_FLOAT, 'v' => $text];
    }

    /**
     * Non-UTF-8 strings (from `Gen::bytes()`) cannot be JSON-encoded verbatim.
     */
    private static function encodeString(string $value): mixed
    {
        return mb_check_encoding($value, 'UTF-8')
            ? $value
            : [self::TAG => self::TAG_BYTES, 'v' => base64_encode($value)];
    }

    /**
     * @param array<array-key, mixed> $value
     * @return ?array{0: mixed}
     */
    private static function encodeArray(array $value): ?array
    {
        $pairs = [];

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            $encoded = self::encode($item);

            if ($encoded === null) {
                return null;
            }

            $pairs[] = [self::encodeString((string) $key), is_int($key), $encoded[0]];
        }

        return [[self::TAG => self::TAG_ARRAY, 'p' => $pairs]];
    }

    /**
     * @param array<array-key, mixed> $encoded
     * @return ?array{0: mixed}
     */
    private static function decodeArray(array $encoded): ?array
    {
        if (!isset($encoded['p']) || !is_array($encoded['p'])) {
            return null;
        }

        $pairs = [];

        /** @var mixed $pair */
        foreach ($encoded['p'] as $pair) {
            $decoded = self::decodePair($pair);

            if ($decoded === null) {
                return null;
            }

            $pairs[] = $decoded;
        }

        return [array_combine(
            array_map(static fn(array $pair): int|string => $pair[0], $pairs),
            array_map(static fn(array $pair): mixed => $pair[1], $pairs),
        )];
    }

    /**
     * One `[key, isIntegerKey, value]` triple of an encoded array, decoded into a
     * `[key, value]` pair, or null when the triple is malformed.
     *
     * @return ?array{0: int|string, 1: mixed}
     */
    private static function decodePair(mixed $pair): ?array
    {
        if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 3) {
            return null;
        }

        $key = self::decode($pair[0]);
        $item = self::decode($pair[2]);

        if ($key === null || $item === null || !is_string($key[0]) || !is_bool($pair[1])) {
            return null;
        }

        return [$pair[1] ? (int) $key[0] : $key[0], $item[0]];
    }

    /**
     * @param array<array-key, mixed> $encoded
     * @return ?array{0: float}
     */
    private static function decodeFloat(array $encoded): ?array
    {
        $text = self::stringField($encoded, 'v');

        if ($text === self::FLOAT_NAN) {
            // Computed, not the NAN constant: Psalm 6.16 crashes when it has to
            // infer a literal-float type for it (TLiteralFloat coerces to string).
            return [fdiv(0.0, 0.0)];
        }

        return match (true) {
            $text === self::FLOAT_INF => [INF],
            $text === self::FLOAT_NEGATIVE_INF => [-INF],
            // No token above is numeric, so a numeric payload is unambiguous.
            $text !== null && is_numeric($text) => [(float) $text],
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $encoded
     * @return ?array{0: string}
     */
    private static function decodeBytes(array $encoded): ?array
    {
        $payload = self::stringField($encoded, 'v');

        if ($payload === null) {
            return null;
        }

        $decoded = base64_decode($payload, strict: true);

        return $decoded === false ? null : [$decoded];
    }

    /**
     * The enum class may have been renamed or the case removed since the corpus
     * entry was written — both make the entry unusable, not fatal.
     *
     * @param array<array-key, mixed> $encoded
     * @return ?array{0: \UnitEnum}
     */
    private static function decodeEnum(array $encoded): ?array
    {
        $class = self::stringField($encoded, 'c');
        $name = self::stringField($encoded, 'n');

        if ($class === null || $name === null || !enum_exists($class)) {
            return null;
        }

        foreach ((new \ReflectionEnum($class))->getCases() as $case) {
            if ($case->getName() === $name) {
                return [$case->getValue()];
            }
        }

        return null;
    }

    /**
     * The value of $encoded[$key] when it is a string, null otherwise (absent, or
     * a payload of the wrong shape).
     *
     * @param array<array-key, mixed> $encoded
     */
    private static function stringField(array $encoded, string $key): ?string
    {
        /** @var mixed $value */
        $value = $encoded[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
