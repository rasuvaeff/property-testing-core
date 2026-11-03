<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

/**
 * Computes the in-range boundary values that numeric and string arbitraries
 * bias toward, because bugs cluster at edges (0, ±1, the range ends, the empty
 * string, a trailing newline) far more than in the uniform interior.
 *
 * @internal
 */
final class Boundary
{
    /**
     * Distinct in-range integers from {0, 1, -1, min, max}, in interest order.
     *
     * @return non-empty-list<int> always contains at least min and max
     */
    public static function ints(int $min, int $max): array
    {
        /** @var list<int> $result */
        $result = [];

        foreach ([0, 1, -1, $min, $max] as $candidate) {
            if ($candidate >= $min && $candidate <= $max && !in_array($candidate, $result, strict: true)) {
                $result[] = $candidate;
            }
        }

        /** @var non-empty-list<int> $result */
        return $result;
    }

    /**
     * Distinct boundary floats from {0.0, min}. The upper bound is excluded
     * because {@see \Rasuvaeff\PropertyTesting\Arbitrary\FloatArbitrary} generates
     * the half-open range [min, max), so max is never a valid value.
     *
     * @return list<float> may be empty for a degenerate range (min == max)
     */
    public static function floats(float $min, float $max): array
    {
        /** @var list<float> $result */
        $result = [];

        foreach ([0.0, $min] as $candidate) {
            if ($candidate >= $min && $candidate < $max && !in_array($candidate, $result, strict: true)) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    /**
     * Strings every text-handling bug list starts with, in interest order:
     * the empty string, a lone space, `"0"`, then — for the Unicode alphabet
     * only, since printable ASCII has none of these characters — a lone line
     * feed, a trailing line feed (`"a\n"` passes `/^[a-z]+$/`) and a
     * decomposed `é` (`e` + combining acute accent). Only those whose length
     * in characters lies within `[$minLength, $maxLength]` are kept, so the
     * list is empty when no candidate fits.
     *
     * @return list<string>
     */
    public static function strings(int $minLength, int $maxLength, bool $unicode): array
    {
        $candidates = $unicode
            ? ['', ' ', '0', "\n", "a\n", "e\u{0301}"]
            : ['', ' ', '0'];

        return array_values(array_filter(
            $candidates,
            static function (string $candidate) use ($minLength, $maxLength): bool {
                $length = mb_strlen($candidate, 'UTF-8');

                return $length >= $minLength && $length <= $maxLength;
            },
        ));
    }
}
