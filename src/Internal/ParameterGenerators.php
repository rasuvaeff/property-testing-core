<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Generate;

/**
 * The parameters of one signature, as generators.
 *
 * Shared by {@see \Rasuvaeff\PropertyTesting\Arbitrary\ClassArbitrary} (a
 * constructor's parameters, instantiated) and
 * {@see \Rasuvaeff\PropertyTesting\Gen::forParameters()} (any function's
 * parameters, handed back as a map). The resolution rules are one thing —
 * an explicit override, then a {@see Generate} attribute on the parameter, then
 * the docblock ({@see DocblockTypes}), then the native type —
 * and $subject is the only difference between the callers: it names whose
 * parameter a refusal is about.
 *
 * @internal
 */
final class ParameterGenerators
{
    private function __construct()
    {
        // Static helpers; not instantiable.
    }

    /**
     * The generator of one constructor's arguments, keyed by parameter name.
     *
     * @param class-string $class
     * @param array<string, ArbitraryInterface> $overrides
     * @param list<class-string> $chain The classes already being built, for the cycle message.
     *
     * @return ArbitraryInterface<array<string, mixed>>
     */
    public static function forConstructor(string $class, array $overrides, int $maxDepth, array $chain): ArbitraryInterface
    {
        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            // The chain matters more than the class here. A VO with a private
            // constructor and named factories (a Duration, a Money) is usually
            // reached from three levels up, and "Duration is not instantiable"
            // sends the reader hunting for which parameter asked for it.
            throw new \InvalidArgumentException(sprintf(
                'Cannot generate %s: it is not instantiable%s; pass an override',
                $class,
                self::via($chain),
            ));
        }

        $constructor = $reflection->getConstructor();

        if (!$constructor instanceof \ReflectionMethod) {
            /** @var array<string, mixed> $arguments */
            $arguments = [];

            return Gen::constant($arguments);
        }

        return Gen::record(self::forSignature($constructor, $class, $overrides, $maxDepth, $chain));
    }

    /**
     * Generators for every parameter of $function, keyed by name in signature
     * order.
     *
     * @param string $subject Whose parameters these are, for the refusal messages.
     * @param array<string, ArbitraryInterface> $overrides
     * @param list<class-string> $chain
     * @param bool $derive Whether a parameter with neither an override nor a
     *        {@see Generate} attribute is derived from its declared type, or refused.
     *
     * @return array<string, ArbitraryInterface>
     */
    public static function forSignature(
        \ReflectionFunctionAbstract $function,
        string $subject,
        array $overrides,
        int $maxDepth,
        array $chain,
        bool $derive = true,
    ): array {
        $documented = DocblockTypes::of($function);
        $resolveClass = self::classResolver($function);
        $shape = [];

        $unknown = array_diff_key($overrides, array_flip(array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $function->getParameters(),
        )));

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot generate %s: override for $%s names no parameter of it',
                $subject,
                implode(', $', array_keys($unknown)),
            ));
        }

        foreach ($function->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (isset($overrides[$name])) {
                $shape[$name] = $overrides[$name];

                continue;
            }

            $attributed = self::fromAttribute($subject, $parameter);

            if ($attributed instanceof ArbitraryInterface) {
                $shape[$name] = $attributed;

                continue;
            }

            if ($parameter->isVariadic()) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot generate %s: parameter $%s is variadic; pass an override or #[Generate]',
                    $subject,
                    $name,
                ));
            }

            if (!$derive) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot generate %s: parameter $%s has no generator; pass an override or #[Generate]',
                    $subject,
                    $name,
                ));
            }

            $shape[$name] = self::generatorFor($subject, $parameter, $documented[$name] ?? null, $maxDepth, $chain, $resolveClass);
        }

        return $shape;
    }

    /**
     * The generator a {@see Generate} attribute on $parameter names, or null
     * when it carries none. Every way the attribute can be wrong — arguments
     * its constructor or PHP rejects, a reference that is not a static
     * factory, a factory returning something else — is a refusal naming the
     * parameter: the throw happens when the attribute is read, far from where
     * it was written.
     */
    private static function fromAttribute(string $subject, \ReflectionParameter $parameter): ?ArbitraryInterface
    {
        $attributes = $parameter->getAttributes(Generate::class);

        if ($attributes === []) {
            return null;
        }

        try {
            $generator = $attributes[0]->newInstance()->generator;
        } catch (\Throwable $failure) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot generate %s: #[Generate] on parameter $%s cannot be built: %s',
                $subject,
                $parameter->getName(),
                $failure->getMessage(),
            ), 0, $failure);
        }

        if ($generator instanceof ArbitraryInterface) {
            return $generator;
        }

        $factory = self::factory($subject, $parameter, $generator);

        try {
            /** @var mixed $built */
            $built = $factory();
        } catch (\Throwable $failure) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot generate %s: #[Generate] on parameter $%s names a factory that threw: %s',
                $subject,
                $parameter->getName(),
                $failure->getMessage(),
            ), 0, $failure);
        }

        if (!$built instanceof ArbitraryInterface) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot generate %s: #[Generate] on parameter $%s names a factory that returned %s, not an ArbitraryInterface',
                $subject,
                $parameter->getName(),
                get_debug_type($built),
            ));
        }

        return $built;
    }

    /**
     * The static factory a {@see Generate} reference denotes. A bare method
     * name is looked up on the declaring class first, the way a provider name
     * is, so a local factory wins over a global function of the same name.
     */
    private static function factory(string $subject, \ReflectionParameter $parameter, array|object|string $reference): Closure
    {
        $class = $parameter->getDeclaringClass();

        if (is_string($reference) && $class instanceof \ReflectionClass && $class->hasMethod($reference)) {
            $method = $class->getMethod($reference);

            if (!$method->isStatic()) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot generate %s: #[Generate] on parameter $%s names %s::%s(), which is not static',
                    $subject,
                    $parameter->getName(),
                    $class->getName(),
                    $reference,
                ));
            }

            return $method->getClosure();
        }

        if (is_callable($reference)) {
            return Closure::fromCallable($reference);
        }

        throw new \InvalidArgumentException(sprintf(
            'Cannot generate %s: #[Generate] on parameter $%s takes an ArbitraryInterface or a static factory returning one, got %s',
            $subject,
            $parameter->getName(),
            is_string($reference) ? sprintf('"%s"', $reference) : get_debug_type($reference),
        ));
    }

    /**
     * The class a name written in $function's docblock denotes, or null.
     *
     * A fully qualified name is taken as is. An unqualified one is looked up
     * the way PHP would in that file: an imported alias by its first segment,
     * then the declaring namespace, then the global namespace — so
     * `LineItem` in a docblock means what `LineItem` means in the code
     * beneath it.
     *
     * @return Closure(string): ?string
     */
    private static function classResolver(\ReflectionFunctionAbstract $function): Closure
    {
        $imports = DocblockTypes::imports($function);

        return static function (string $name) use ($imports): ?string {
            $exists = static fn(string $class): bool => class_exists($class) || interface_exists($class) || enum_exists($class);

            if (str_starts_with($name, '\\')) {
                $class = substr($name, 1);

                return $exists($class) ? $class : null;
            }

            $segments = explode('\\', $name);
            $alias = $imports['aliases'][strtolower($segments[0])] ?? null;

            $candidates = [
                ...($alias === null ? [] : [implode('\\', [$alias, ...array_slice($segments, 1)])]),
                ...($imports['namespace'] === '' ? [] : [$imports['namespace'] . '\\' . $name]),
                $name,
            ];

            foreach ($candidates as $candidate) {
                if ($exists($candidate)) {
                    return $candidate;
                }
            }

            return null;
        };
    }

    /**
     * @param list<class-string> $chain
     * @param Closure(string): ?string $resolveClass
     */
    private static function generatorFor(
        string $subject,
        \ReflectionParameter $parameter,
        ?string $documented,
        int $maxDepth,
        array $chain,
        Closure $resolveClass,
    ): ArbitraryInterface {
        $forClass = static function (string $type) use ($maxDepth, $chain): ArbitraryInterface {
            if (in_array($type, $chain, strict: true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot generate %s: it is reachable from itself (%s); pass an override to break the cycle',
                    $type,
                    implode(' -> ', [...$chain, $type]),
                ));
            }

            if ($maxDepth < 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot generate %s: maximum depth reached (%s); raise maxDepth or pass an override',
                    $type,
                    implode(' -> ', [...$chain, $type]),
                ));
            }

            if (enum_exists($type)) {
                /** @var class-string<\UnitEnum> $type */
                return Gen::enum($type);
            }

            if ($type === \DateTimeImmutable::class) {
                return Gen::datetime();
            }

            // The native random API: an engine (or a randomizer over one)
            // whose decisions ride the draw tape, so they shrink.
            if ($type === \Random\Engine::class) {
                return Gen::randomEngine();
            }

            if ($type === \Random\Randomizer::class) {
                return Gen::randomizer();
            }

            if (is_a($type, \DateTimeInterface::class, allow_string: true)) {
                // Only the exact class has a generator. Anything else that
                // implements the interface — a subclass, DateTime — inherits a
                // constructor whose reflection asks for a `string` and a
                // `?DateTimeZone`, and a random string never parses as a date:
                // building it would explode with a parse error deep inside the
                // recursion instead of naming the type that cannot be built.
                throw new \InvalidArgumentException(sprintf(
                    'Cannot generate %s: only DateTimeImmutable itself is generated from a signature; pass an override',
                    $type,
                ));
            }

            /** @var class-string $type */
            return Gen::map(
                self::forConstructor($type, [], $maxDepth - 1, [...$chain, $type]),
                // Reflection rather than `new $type(...)`: the arguments are
                // keyed by parameter name, which reflection applies as named
                // arguments, and a dynamic class-string is something static
                // analysis can follow here and cannot there.
                static fn(array $arguments): object => (new \ReflectionClass($type))->newInstance(...$arguments),
            );
        };

        $native = $parameter->getType();

        if ($documented !== null) {
            $fromDocblock = TypeGenerators::fromDocblock($documented, $forClass, $resolveClass);

            if ($fromDocblock instanceof ArbitraryInterface) {
                return $fromDocblock;
            }

            // A docblock type outside the readable subset says more than the
            // native type — that is why it was written — and generating from
            // the native type instead would be the widened guess the class
            // promises never to make: `float<0.0, 1.0>` is not `float`. The
            // one exception is a native class type, whose docblock can only
            // narrow its generics (`Collection<Item>`), never its values.
            if (!$native instanceof \ReflectionNamedType || $native->isBuiltin()) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot generate %s: parameter $%s is documented as %s, which this cannot read%s; pass an override or #[Generate]',
                    $subject,
                    $parameter->getName(),
                    $documented,
                    self::unknownClasses($documented, $resolveClass, self::templates($parameter)),
                ));
            }
        }

        if ($native instanceof \ReflectionNamedType) {
            $generator = TypeGenerators::fromNative($native->getName(), $forClass);

            if ($generator instanceof ArbitraryInterface) {
                return $native->allowsNull()
                    ? Gen::nullable($generator)
                    : $generator;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Cannot generate %s: parameter $%s is %s, which this cannot read; pass an override or #[Generate]',
            $subject,
            $parameter->getName(),
            match (true) {
                $documented !== null => 'typed ' . $documented,
                $native instanceof \ReflectionNamedType => 'typed ' . $native->getName(),
                // A union or intersection type: reflection prints it as written.
                $native instanceof \ReflectionType => 'typed ' . $native->__toString(),
                default => 'untyped',
            },
        ));
    }

    /**
     * ` (unknown class "Nope")` for every capitalised name in $type the file
     * cannot resolve, or nothing. A refusal that only repeats the docblock
     * leaves the reader checking the supported subset, when the cause is a
     * typo or a missing `use` — the one thing worth saying is which name.
     *
     * @param Closure(string): ?string $resolveClass
     */
    /**
     * The generic names the parameter's function and class declare
     * (`@template T`, `@template-covariant TKey`, and the psalm/phpstan
     * spellings): a type written with one is unreadable, but `T` is not an
     * unknown class and the message must not call it one.
     *
     * @return list<string>
     */
    private static function templates(\ReflectionParameter $parameter): array
    {
        $function = $parameter->getDeclaringFunction();
        $docblocks = [$function->getDocComment()];

        if ($function instanceof \ReflectionMethod) {
            $docblocks[] = $function->getDeclaringClass()->getDocComment();
        }

        $names = [];

        foreach ($docblocks as $docblock) {
            if ($docblock === false) {
                continue;
            }

            if (preg_match_all('/@(?:psalm-|phpstan-)?template(?:-covariant|-contravariant)?\s+([A-Za-z_][A-Za-z0-9_]*)/', $docblock, $matches) > 0) {
                $names = [...$names, ...$matches[1]];
            }
        }

        return $names;
    }

    /**
     * @param list<string> $templates
     */
    private static function unknownClasses(string $type, Closure $resolveClass, array $templates = []): string
    {
        // Capitalised or fully qualified names only: the lower-case words of a
        // type expression are its keywords (`int`, `list`, `array-key`) and
        // the keys of an `array{a: int}` shape, never a class.
        if (preg_match_all('/(?<![\w\'\\\\-])\\\\?[A-Z][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*(?![\w\'-])/', $type, $matches) === false) {
            return '';
        }

        $unknown = [];

        foreach (array_unique($matches[0]) as $name) {
            if (in_array($name, $templates, strict: true)) {
                continue;
            }

            if ($resolveClass($name) === null) {
                $unknown[] = sprintf('unknown class "%s"', $name);
            }
        }

        return $unknown === [] ? '' : ' (' . implode(', ', $unknown) . ')';
    }

    /**
     * ` (reached through A -> B -> C)`, or nothing when the class is the one
     * that was asked for — a chain of one says nothing worth reading.
     *
     * @param list<class-string> $chain
     */
    private static function via(array $chain): string
    {
        return count($chain) < 2 ? '' : sprintf(' (reached through %s)', implode(' -> ', $chain));
    }
}
