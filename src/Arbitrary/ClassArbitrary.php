<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\ParameterGenerators;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Instances of a class, generated from what its constructor already declares.
 *
 * PHP has no macros, so this is reflection — but in a codebase with promoted
 * constructor properties and psalm annotations, the information a
 * `derive(Arbitrary)` macro would need is already written down and currently
 * goes to waste. A parameter typed `int<0, 100>` describes its value space
 * exactly; guessing `int` there and letting the constructor reject four
 * generated values in five is the difference between a usable generator and a
 * demo.
 *
 * ```php
 * Gen::forClass(Money::class);
 * Gen::forClass(Money::class, ['amount' => Gen::intPositive()]);   // override one parameter
 * ```
 *
 * Types are read in this order, per parameter: an explicit override, then the
 * `@param` docblock (psalm subset — `int<0, 100>`, `positive-int`,
 * `non-empty-string`, `list<T>`, `array<K, V>`, `'a'|'b'`, `?T`, unions), then
 * the native type. Anything it cannot read is an exception naming the
 * parameter, never a widened guess: a generator that does not match the domain
 * turns into somebody else's failing test.
 *
 * **A validating constructor is not a special case.** Constructors in this
 * family reject bad input, and by default a rejection propagates — it says the
 * generator does not match the domain, and an override or a narrower
 * annotation is the fix. `skipInvalid: true` is the deliberate opposite: the
 * value is discarded and redrawn, up to {@see MAX_ATTEMPTS} times, exactly as
 * {@see Gen::filter()} does. Only exceptions are
 * discarded, never `Error`s: a `TypeError` means the generator produced the
 * wrong type, which is a bug rather than a rejected value.
 *
 * @template TValue of object
 * @implements ArbitraryInterface<TValue>
 * @api
 */
final readonly class ClassArbitrary implements ArbitraryInterface
{
    /** @var ArbitraryInterface<array<string, mixed>> */
    private ArbitraryInterface $arguments;

    /**
     * @param class-string<TValue> $class The class to instantiate. Must be concrete and constructible.
     * @param array<string, ArbitraryInterface> $overrides Generators by constructor parameter name,
     *        winning over anything the parameter declares. The way to narrow a value space the type
     *        cannot express, and to break a recursion this cannot.
     * @param bool $skipInvalid Whether a constructor that rejects a generated value discards it and
     *        redraws instead of failing the run.
     * @param int $maxDepth How deep to follow class-typed parameters before refusing. Cycles are an
     *        error rather than a hang, and the message names the chain.
     */
    public function __construct(
        private string $class,
        array $overrides = [],
        private bool $skipInvalid = false,
        int $maxDepth = 3,
    ) {
        $this->arguments = ParameterGenerators::forConstructor($this->class, $overrides, $maxDepth, [$this->class]);
    }

    /**
     * @param Random $random The run's source of randomness, threaded through every parameter's generator.
     *
     * @return Shrinkable<TValue>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        // With skipInvalid the filter does both halves of the job: it redraws
        // a rejected argument set (up to its own hundred attempts, then
        // GenerationExhausted) and prunes shrink candidates the constructor
        // would reject — a smaller set of arguments it refuses is not a
        // smaller value, it is no value at all.
        $arguments = $this->skipInvalid
            ? Gen::filter(
                $this->arguments,
                /** @param array<string, mixed> $arguments */
                fn(array $arguments): bool => $this->constructible($arguments),
            )
            : $this->arguments;

        return $arguments->generate($random)->map(fn(array $arguments): object => $this->instantiate($arguments));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return TValue
     */
    private function instantiate(array $arguments): object
    {
        return (new \ReflectionClass($this->class))->newInstance(...$arguments);
    }

    /**
     * Whether the constructor accepts these arguments.
     *
     * Only exceptions count as a rejection. An `Error` — a `TypeError` above
     * all — says the generator produced the wrong type, and swallowing that
     * would turn a bug in this class into an empty value space.
     *
     * @param array<string, mixed> $arguments
     */
    private function constructible(array $arguments): bool
    {
        try {
            $this->instantiate($arguments);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
