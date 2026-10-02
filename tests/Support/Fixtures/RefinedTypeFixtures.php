<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support\Fixtures;

/**
 * The refined string types and the array shapes the docblock reader has to
 * understand, and the shape forms it has to refuse. Reflected, never called.
 */
final class RefinedTypeMethods
{
    /**
     * @param numeric-string $numeric
     * @param non-falsy-string $nonFalsy
     * @param truthy-string $truthy
     * @param lowercase-string $lower
     * @param non-empty-lowercase-string $nonEmptyLower
     */
    public function strings(string $numeric, string $nonFalsy, string $truthy, string $lower, string $nonEmptyLower): void {}

    /**
     * @param array{id: positive-int, name?: non-empty-string, 'with space': bool, 3: int<0, 9>} $keyed
     * @param array{int<0, 9>, 'a'|'b'} $positional
     * @param list{bool, int<1, 1>} $list
     * @param array{} $empty
     * @param array{a?: ?int} $optionalNullable
     * @param array<string, array{a: int<0, 9>, b: int<0, 9>}> $nested
     * @param array{a: int<0, 9>}|null $shapeOrNull
     * @param array{
     *     a: int<0, 9>,
     *     b: bool,
     * } $multiline
     */
    public function shapes(
        array $keyed,
        array $positional,
        array $list,
        array $empty,
        array $optionalNullable,
        array $nested,
        ?array $shapeOrNull,
        array $multiline,
    ): void {}

    /**
     * @param array{a: int, ...} $shape
     */
    public function unsealed(array $shape): void {}

    /**
     * @param list{a: int} $shape
     */
    public function keyedList(array $shape): void {}

    /**
     * @param array{"a": int} $shape
     */
    public function doubleQuotedKey(array $shape): void {}

    /**
     * @param array{a: float<0.0, 1.0>} $shape
     */
    public function unreadableValue(array $shape): void {}
}
