<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * Renders an arbitrary value into a compact, human-readable string for
 * counterexample reporting. Unlike a bare `[N element(s)]`/class-name summary it
 * descends into arrays and simple objects so the shrunk value is actually
 * visible, while staying bounded: depth, element count and string length are all
 * capped, special floats and binary strings are labelled, and object recursion
 * is guarded against cycles.
 *
 * @api
 */
final class ValueRenderer
{
    private const int MAX_DEPTH = 4;
    private const int MAX_ELEMENTS = 8;
    private const int MAX_STRING = 64;

    public static function render(mixed $value): string
    {
        return self::format($value, self::MAX_DEPTH, []);
    }

    public static function normalize(mixed $value): mixed
    {
        return self::normalizeValue($value, 32, []);
    }

    public static function exportPhp(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::exportFloat($value),
            is_string($value) => var_export($value, return: true),
            is_array($value) => self::exportArray($value),
            $value instanceof \UnitEnum => '\\' . $value::class . '::' . $value->name,
            default => throw new \LogicException(sprintf(
                'Cannot export %s as runnable PHP example code',
                get_debug_type($value),
            )),
        };
    }

    /**
     * @param list<int> $seen spl_object_id trail guarding against object cycles.
     */
    private static function format(mixed $value, int $depth, array $seen): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            is_string($value) => self::string($value),
            is_array($value) => self::array($value, $depth, $seen),
            is_object($value) => self::object($value, $depth, $seen),
            default => get_debug_type($value),
        };
    }

    private static function float(float $value): string
    {
        // (string) already yields 'NAN', 'INF' and '-INF'; only negative zero
        // needs help — it collapses to "0". Detect it with fdiv() (the `/`
        // operator throws DivisionByZeroError on a zero divisor).
        if ($value === 0.0 && fdiv(1.0, $value) === -INF) {
            return '-0.0';
        }

        return (string) $value;
    }

    private static function string(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $bytes = strlen($value);
            $hex = bin2hex(substr($value, 0, self::MAX_STRING));

            return sprintf('b"0x%s%s" (%d byte(s))', $hex, $bytes > self::MAX_STRING ? '…' : '', $bytes);
        }

        if (mb_strlen($value, 'UTF-8') > self::MAX_STRING) {
            return sprintf('"%s…" (%d chars)', self::escape(mb_substr($value, 0, self::MAX_STRING, 'UTF-8')), mb_strlen($value, 'UTF-8'));
        }

        return '"' . self::escape($value) . '"';
    }

    /**
     * Escape the quote, backslash and control characters so a value containing
     * `"`, newlines or tabs stays on one unambiguous line instead of breaking the
     * counterexample across lines. Multibyte (>= 0x80) bytes are left untouched.
     */
    private static function escape(string $value): string
    {
        return addcslashes($value, "\0..\37\"\\\177");
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<int> $seen
     */
    private static function array(array $value, int $depth, array $seen): string
    {
        if ($value === []) {
            return '[]';
        }

        if ($depth <= 0) {
            return sprintf('[… %d element(s)]', count($value));
        }

        $isList = array_is_list($value);
        $rendered = [];
        $shown = 0;

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            if ($shown >= self::MAX_ELEMENTS) {
                break;
            }

            $rendered[] = $isList
                ? self::format($item, $depth - 1, $seen)
                : self::keyLabel($key) . ' => ' . self::format($item, $depth - 1, $seen);
            ++$shown;
        }

        $remaining = count($value) - $shown;
        if ($remaining > 0) {
            $rendered[] = sprintf('… +%d more', $remaining);
        }

        return '[' . implode(', ', $rendered) . ']';
    }

    private static function keyLabel(int|string $key): string
    {
        return is_int($key) ? (string) $key : self::string($key);
    }

    /**
     * @param list<int> $seen
     */
    private static function object(object $value, int $depth, array $seen): string
    {
        if ($value instanceof \UnitEnum) {
            return $value::class . '::' . $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.uP');
        }

        // Stringable includes the package's own CommandSequence/Command traces —
        // keep their string form unquoted so the trace reads naturally, but apply
        // the same escaping and length bound as ordinary strings.
        if ($value instanceof \Stringable) {
            return self::stringable((string) $value);
        }

        $id = spl_object_id($value);
        if (in_array($id, $seen, strict: true)) {
            return $value::class . ' {*recursion*}';
        }

        $properties = self::objectProperties($value);
        if ($properties === []) {
            return $value::class . ' {}';
        }

        if ($depth <= 0) {
            return $value::class . ' {…}';
        }

        $seen[] = $id;
        $rendered = [];
        $shown = 0;

        /** @var mixed $item */
        foreach ($properties as $name => $item) {
            if ($shown >= self::MAX_ELEMENTS) {
                break;
            }

            $rendered[] = $name . ': ' . self::format($item, $depth - 1, $seen);
            ++$shown;
        }

        $remaining = count($properties) - $shown;
        if ($remaining > 0) {
            $rendered[] = sprintf('… +%d more', $remaining);
        }

        return $value::class . ' {' . implode(', ', $rendered) . '}';
    }

    /**
     * All non-static instance properties, including private and protected ones
     * (typical of constructor-promoted DTOs) that {@see get_object_vars()} would
     * hide when called from outside the class. Uninitialised typed properties are
     * shown as a marker rather than read (which would throw).
     *
     * @return array<string, mixed>
     */
    private static function objectProperties(object $value): array
    {
        /** @var list<\ReflectionProperty> $instanceProperties */
        $instanceProperties = [];
        $class = new \ReflectionObject($value);

        do {
            foreach ($class->getProperties() as $property) {
                if (!$property->isStatic() && $property->getDeclaringClass()->getName() === $class->getName()) {
                    $instanceProperties[] = $property;
                }
            }

            $parent = $class->getParentClass();
            $class = $parent === false ? null : $parent;
        } while ($class instanceof \ReflectionClass);

        $plainNames = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            $instanceProperties,
        );
        $nameCounts = array_count_values($plainNames);

        // Built via array_combine (not per-key assignment) so the mixed property
        // values do not trip Psalm's MixedAssignment at errorLevel 1.
        return array_combine(
            array_map(
                static fn(\ReflectionProperty $property): string => $nameCounts[$property->getName()] > 1
                    ? $property->getDeclaringClass()->getShortName() . '::$' . $property->getName()
                    : $property->getName(),
                $instanceProperties,
            ),
            array_map(
                static fn(\ReflectionProperty $property): mixed => $property->isInitialized($value)
                    ? $property->getValue($value)
                    : '<uninitialized>',
                $instanceProperties,
            ),
        );
    }

    private static function stringable(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return self::string($value);
        }

        $length = mb_strlen($value, 'UTF-8');
        if ($length > self::MAX_STRING) {
            return self::escape(mb_substr($value, 0, self::MAX_STRING, 'UTF-8')) . sprintf('… (%d chars)', $length);
        }

        return self::escape($value);
    }

    /**
     * @param list<int> $seen
     */
    private static function normalizeValue(mixed $value, int $depth, array $seen): mixed
    {
        if ($depth <= 0) {
            return ['__truncated' => true];
        }

        if (is_float($value) && !is_finite($value)) {
            return (string) $value;
        }

        if (!is_array($value) && !is_object($value)) {
            return is_resource($value) ? get_debug_type($value) : $value;
        }

        if (is_array($value)) {
            return array_map(
                static fn(mixed $item): mixed => self::normalizeValue($item, $depth - 1, $seen),
                $value,
            );
        }

        if ($value instanceof \BackedEnum) {
            return ['__type' => $value::class, 'case' => $value->name, 'value' => $value->value];
        }

        if ($value instanceof \UnitEnum) {
            return ['__type' => $value::class, 'case' => $value->name];
        }

        if ($value instanceof \DateTimeInterface) {
            return ['__type' => $value::class, 'value' => $value->format('Y-m-d H:i:s.uP')];
        }

        $id = spl_object_id($value);
        if (in_array($id, $seen, strict: true)) {
            return ['__type' => $value::class, '__recursion' => true];
        }

        $seen[] = $id;
        $properties = array_map(
            static fn(mixed $item): mixed => self::normalizeValue($item, $depth - 1, $seen),
            self::objectProperties($value),
        );

        if ($value instanceof \Stringable) {
            $properties['__string'] = (string) $value;
        }

        return [
            '__type' => $value::class,
            'properties' => $properties,
        ];
    }

    private static function exportFloat(float $value): string
    {
        // var_export() already renders every float as a runnable literal: the
        // non-finite values as the NAN/INF/-INF constants, integral values with
        // a ".0" suffix (so 2.0 stays '2.0', not '2') and negative zero with
        // its sign ('-0.0'). Pinned by the exportsRunnablePhpValues test.
        return var_export($value, return: true);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function exportArray(array $value): string
    {
        $isList = array_is_list($value);
        $items = [];

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            $items[] = $isList
                ? self::exportPhp($item)
                : self::exportPhp($key) . ' => ' . self::exportPhp($item);
        }

        return '[' . implode(', ', $items) . ']';
    }
}
