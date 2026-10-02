<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * The generator of one parameter, written on the parameter itself — read by
 * {@see Gen::forParameters()} and {@see Gen::forClass()}, so by every adapter's
 * signature-driven mode, before the docblock and the native type.
 *
 * ```php
 * public function delayStaysWithinCap(
 *     #[Generate(new IntArbitrary(0, 10_000))] int $base,
 *     #[Generate([Generators::class, 'email'])] string $to,
 * ): void
 * ```
 *
 * Either an arbitrary, built with `new` (an attribute argument is a constant
 * expression, so `Gen::*` factories cannot appear in it), or a reference to a
 * static factory that returns one: a method name of the declaring class,
 * `'Class::method'`, `[Class::class, 'method']`, or an invokable object.
 * The reference is how a generator that needs a closure — `Gen::map()`,
 * `Gen::email()`, `Gen::regex()` — reaches a parameter on PHP 8.3 and 8.4.
 *
 * The attribute is exact: it wins over the docblock and the native type, and
 * a nullable parameter is not made nullable on top of it.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Generate
{
    /**
     * @param ArbitraryInterface|non-empty-string|array{0: class-string|object, 1: non-empty-string}|object $generator
     *        The arbitrary, or a static factory returning one.
     */
    public function __construct(
        public array|object|string $generator,
    ) {}
}
