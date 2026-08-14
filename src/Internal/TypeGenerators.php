<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * A written type, as a generator.
 *
 * Two sources, in that order of precedence: the psalm/PHPDoc type when the
 * parameter has one, the native type otherwise. The docblock wins because it
 * says more — `int` and `int<0, 100>` are the same native type and a very
 * different value space, and the narrower one is the one that keeps a
 * validating constructor from throwing.
 *
 * The supported subset is bounded on purpose, in the same way
 * {@see \Rasuvaeff\PropertyTesting\Gen::regex()} supports a subset of PCRE: a
 * type this cannot read is an exception naming the parameter, never a silently
 * widened guess. Guessing here would produce a generator that does not match
 * the domain, and the failure would surface as somebody else's bug.
 *
 * @internal
 */
final class TypeGenerators
{
    private function __construct()
    {
        // Static helpers; not instantiable.
    }

    /**
     * The generator for a psalm/PHPDoc type expression, or null when the
     * expression is outside the supported subset (the caller then falls back
     * to the native type, or reports it).
     *
     * @param string $type The type expression, as written in the docblock.
     * @param Closure(string): ArbitraryInterface $forClass Resolver for a class type — recursion
     *        lives with the caller, which is what tracks depth and cycles.
     */
    public static function fromDocblock(string $type, Closure $forClass): ?ArbitraryInterface
    {
        $type = trim($type);

        if ($type === '') {
            return null;
        }

        // ?T is a union with null, spelled shorter.
        if (str_starts_with($type, '?')) {
            $inner = self::fromDocblock(substr($type, 1), $forClass);

            return $inner === null ? null : Gen::nullable($inner);
        }

        $simple = self::simple($type);

        if ($simple instanceof ArbitraryInterface) {
            return $simple;
        }

        $ranged = self::rangedInt($type);

        if ($ranged instanceof ArbitraryInterface) {
            return $ranged;
        }

        $collection = self::collection($type, $forClass);

        if ($collection instanceof ArbitraryInterface) {
            return $collection;
        }

        return self::union($type, $forClass);
    }

    /**
     * The generator for a native (reflection) type name.
     *
     * @param string $type The type name as reflection reports it.
     * @param Closure(string): ArbitraryInterface $forClass Resolver for a class type.
     */
    public static function fromNative(string $type, Closure $forClass): ?ArbitraryInterface
    {
        return match ($type) {
            'int' => Gen::int(),
            'float' => Gen::floatBetween(-1_000_000.0, 1_000_000.0),
            'string' => Gen::string(),
            'bool' => Gen::bool(),
            default => class_exists($type) || interface_exists($type) || enum_exists($type)
                ? $forClass($type)
                : null,
        };
    }

    /**
     * The types that need no parsing at all.
     */
    private static function simple(string $type): ?ArbitraryInterface
    {
        return match ($type) {
            'int' => Gen::int(),
            'positive-int' => Gen::intPositive(),
            'negative-int' => Gen::intBetween(PHP_INT_MIN, -1),
            'non-negative-int' => Gen::intBetween(0, PHP_INT_MAX),
            'non-positive-int' => Gen::intBetween(PHP_INT_MIN, 0),
            'float' => Gen::floatBetween(-1_000_000.0, 1_000_000.0),
            'string' => Gen::string(),
            'non-empty-string' => Gen::stringOf(1, 100),
            'bool' => Gen::bool(),
            'true' => Gen::constant(true),
            'false' => Gen::constant(false),
            'null' => Gen::constant(null),
            default => null,
        };
    }

    /**
     * `int<0, 100>`, `int<min, 10>`, `int<0, max>`.
     */
    private static function rangedInt(string $type): ?ArbitraryInterface
    {
        if (preg_match('/^int<\s*(min|-?\d+)\s*,\s*(max|-?\d+)\s*>\z/', $type, $matches) !== 1) {
            return null;
        }

        return Gen::intBetween(
            $matches[1] === 'min' ? PHP_INT_MIN : (int) $matches[1],
            $matches[2] === 'max' ? PHP_INT_MAX : (int) $matches[2],
        );
    }

    /**
     * `list<T>`, `non-empty-list<T>`, `array<V>`, `array<K, V>`, `T[]`.
     *
     * @param Closure(string): ArbitraryInterface $forClass
     */
    private static function collection(string $type, Closure $forClass): ?ArbitraryInterface
    {
        if (str_ends_with($type, '[]')) {
            $element = self::fromDocblock(substr($type, 0, -2), $forClass);

            return $element === null ? null : Gen::arrayOf($element, 0, 10);
        }

        if (preg_match('/^(non-empty-list|list|non-empty-array|array)<(.+)>\z/', $type, $matches) !== 1) {
            return null;
        }

        $arguments = self::splitArguments($matches[2]);
        $minimum = str_starts_with($matches[1], 'non-empty-') ? 1 : 0;

        if (count($arguments) === 1) {
            $element = self::fromDocblock($arguments[0], $forClass);

            return $element === null ? null : Gen::arrayOf($element, $minimum, 10);
        }

        if (count($arguments) !== 2 || str_ends_with($matches[1], 'list')) {
            return null;
        }

        /** @var ?ArbitraryInterface<array-key> $key */
        $key = self::fromDocblock($arguments[0], $forClass);
        $value = self::fromDocblock($arguments[1], $forClass);

        return $key === null || $value === null ? null : Gen::dictOf($key, $value, $minimum, 10);
    }

    /**
     * `A|B` — including the literal unions psalm writes for a closed set of
     * values (`'a'|'b'`, `1|2|3`), which are the most useful of all: they are a
     * domain spelled out in the type.
     *
     * @param Closure(string): ArbitraryInterface $forClass
     */
    private static function union(string $type, Closure $forClass): ?ArbitraryInterface
    {
        $members = self::splitUnion($type);

        if (count($members) < 2) {
            return null;
        }

        $literals = self::literals($members);

        if ($literals !== null) {
            return Gen::elements($literals);
        }

        $pairs = [];

        foreach ($members as $member) {
            $arbitrary = self::fromDocblock($member, $forClass);

            if ($arbitrary === null) {
                return null;
            }

            $pairs[] = [1, $arbitrary];
        }

        return Gen::frequency($pairs);
    }

    /**
     * The values of a union written entirely as literals, or null when any
     * member is not one.
     *
     * @param list<string> $members
     *
     * @return ?non-empty-list<mixed>
     */
    private static function literals(array $members): ?array
    {
        $values = [];

        foreach ($members as $member) {
            if (preg_match("/^'([^']*)'\\z/", $member, $matches) === 1) {
                $values[] = $matches[1];

                continue;
            }

            if (preg_match('/^-?\d+\z/', $member) === 1) {
                $values[] = (int) $member;

                continue;
            }

            return null;
        }

        return $values === [] ? null : $values;
    }

    /**
     * Splits `K, V` without cutting inside a nested `<…>`.
     *
     * @return list<string>
     */
    private static function splitArguments(string $arguments): array
    {
        return self::split($arguments, ',');
    }

    /**
     * @return list<string>
     */
    private static function splitUnion(string $type): array
    {
        return self::split($type, '|');
    }

    /**
     * @return list<string>
     */
    private static function split(string $subject, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($subject) as $character) {
            if ($character === '<') {
                ++$depth;
            } elseif ($character === '>') {
                --$depth;
            }

            if ($character === $separator && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = trim($current);

        return $parts;
    }
}
