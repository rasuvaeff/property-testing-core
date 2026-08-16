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

    public function nullableNative(?int $maybe): void {}

    public function withCycle(Cyclic $other): void {}

    public function unreadable(array $anything): void {}

    public function withMixed(mixed $anything): void {}

    public function untyped($anything): void {}

    public function variadic(int ...$numbers): void {}

    public function withoutParameters(): void {}
}
