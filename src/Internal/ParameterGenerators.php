<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Closure;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * The parameters of one signature, as generators.
 *
 * Shared by {@see \Rasuvaeff\PropertyTesting\Arbitrary\ClassArbitrary} (a
 * constructor's parameters, instantiated) and
 * {@see \Rasuvaeff\PropertyTesting\Gen::forParameters()} (any function's
 * parameters, handed back as a map). The resolution rules are one thing —
 * an explicit override, then the `@param` docblock, then the native type —
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
     *
     * @return array<string, ArbitraryInterface>
     */
    public static function forSignature(
        \ReflectionFunctionAbstract $function,
        string $subject,
        array $overrides,
        int $maxDepth,
        array $chain,
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

            if ($parameter->isVariadic()) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot generate %s: parameter $%s is variadic; pass an override',
                    $subject,
                    $name,
                ));
            }

            $shape[$name] = self::generatorFor($subject, $parameter, $documented[$name] ?? null, $maxDepth, $chain, $resolveClass);
        }

        return $shape;
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
                    'Cannot generate %s: parameter $%s is documented as %s, which this cannot read; pass an override',
                    $subject,
                    $parameter->getName(),
                    $documented,
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
            'Cannot generate %s: parameter $%s is typed %s, which this cannot read; pass an override',
            $subject,
            $parameter->getName(),
            $documented ?? ($native instanceof \ReflectionNamedType ? $native->getName() : 'with no usable type'),
        ));
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
