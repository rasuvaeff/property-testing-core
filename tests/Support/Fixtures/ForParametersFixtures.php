<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support\Fixtures;

/**
 * Signatures {@see \Rasuvaeff\PropertyTesting\Gen::forParameters()} has to
 * read — the shapes a property method takes, including the ones it must
 * refuse. Reflected, never called.
 */
final class PropertyMethods
{
    /**
     * @param int<1, 300> $base
     * @param int<1, 86400> $cap
     */
    public function annotated(int $base, int $cap, bool $flag): void {}

    public function native(int $count, float $ratio, string $label, bool $active): void {}

    public function withClass(NativeTypes $inner, bool $flag): void {}

    public function withEnumAndDate(Currency $currency, \DateTimeImmutable $at): void {}

    public function withRandomApi(\Random\Engine $engine, \Random\Randomizer $randomizer): void {}

    public function nullableNative(?int $maybe): void {}

    public function withDateSubclass(CustomDate $at): void {}

    public function withMutableDate(\DateTime $at): void {}

    public function withCycle(Cyclic $other): void {}

    public function unreadable(array $anything): void {}

    public function withMixed(mixed $anything): void {}

    public function untyped($anything): void {}

    public function withNativeUnion(int|string $either): void {}

    /**
     * @param int $x The IDE-facing type; psalm narrows it below.
     * @psalm-param positive-int $x
     */
    public function psalmParam(int $x): void {}

    /**
     * @param list<Nope> $items
     */
    public function withUnknownClass(array $items): void {}

    /**
     * @template T of object
     * @param list<T> $items
     * @param array<string, Nope> $named
     */
    public function withATemplateAndAnUnknownClass(array $items, array $named): void {}

    public function variadic(int ...$numbers): void {}

    public function withoutParameters(): void {}
}

/**
 * A DateTimeImmutable subclass with no constructor of its own: reflection over
 * it reads the inherited `string $datetime` and refuses rather than feeding a
 * random string to a date parser.
 *
 * @internal
 */
final class CustomDate extends \DateTimeImmutable {}
