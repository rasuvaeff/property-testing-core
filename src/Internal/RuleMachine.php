<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\StateMachine\Invariant;
use Rasuvaeff\PropertyTesting\StateMachine\Precondition;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;
use Rasuvaeff\PropertyTesting\StateMachine\RuleStep;

/**
 * Reads a rule-based machine class: which public methods are rules, their
 * guards and argument generators, and which are invariants. Every refusal
 * names the class and the method, at construction rather than mid-run.
 *
 * @internal Driven by {@see Gen::rules()}.
 */
final readonly class RuleMachine
{
    /** @var non-empty-list<ArbitraryInterface<RuleStep>> */
    public array $stepGenerators;

    /** @var list<non-empty-string> */
    public array $invariants;

    /**
     * @param class-string $class The machine class to read.
     */
    public function __construct(string $class)
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Gen::rules(): "%s" is not a class', $class));
        }

        $reflection = new \ReflectionClass($class);
        $invariants = [];
        $rules = [];

        foreach ($reflection->getMethods() as $method) {
            $isRule = $method->getAttributes(Rule::class) !== [];
            $isInvariant = $method->getAttributes(Invariant::class) !== [];

            if (!$isRule && !$isInvariant) {
                continue;
            }

            if (!$method->isPublic() || $method->isStatic()) {
                throw new \InvalidArgumentException(sprintf(
                    'Gen::rules(): %s::%s() must be a public instance method to be a %s',
                    $class,
                    $method->getName(),
                    $isRule ? 'rule' : 'invariant',
                ));
            }

            if ($isInvariant) {
                if ($method->getNumberOfParameters() > 0) {
                    throw new \InvalidArgumentException(sprintf('Gen::rules(): invariant %s::%s() must not take parameters', $class, $method->getName()));
                }

                /** @var non-empty-string $name */
                $name = $method->getName();
                $invariants[] = $name;
            }

            if ($isRule) {
                $rules[] = $method;
            }
        }

        if ($rules === []) {
            throw new \InvalidArgumentException(sprintf('Gen::rules(): %s declares no #[Rule] method', $class));
        }

        $this->invariants = $invariants;

        $generators = [];

        foreach ($rules as $method) {
            $generators[] = $this->stepGenerator($reflection, $method, $invariants);
        }

        $this->stepGenerators = $generators;
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<non-empty-string> $invariants
     *
     * @return ArbitraryInterface<RuleStep>
     */
    private function stepGenerator(\ReflectionClass $class, \ReflectionMethod $method, array $invariants): ArbitraryInterface
    {
        /** @var non-empty-string $rule */
        $rule = $method->getName();
        $precondition = $this->precondition($class, $method);
        $parameters = Gen::forParameters($method, $this->overrides($class, $method));

        /** @var ArbitraryInterface<array<string, mixed>> $arguments */
        $arguments = $parameters === [] ? Gen::constant([]) : Gen::record($parameters);

        return Gen::map(
            $arguments,
            /** @param array<string, mixed> $drawn */
            static fn(array $drawn): RuleStep => new RuleStep($rule, $drawn, $precondition, $invariants),
        );
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return ?non-empty-string
     */
    private function precondition(\ReflectionClass $class, \ReflectionMethod $method): ?string
    {
        $attributes = $method->getAttributes(Precondition::class);

        if ($attributes === []) {
            return null;
        }

        $guard = $attributes[0]->newInstance()->method;

        if (!$class->hasMethod($guard) || !$class->getMethod($guard)->isPublic() || $class->getMethod($guard)->isStatic()) {
            throw new \InvalidArgumentException(sprintf(
                'Gen::rules(): rule %s::%s() names precondition "%s", which is not a public instance method',
                $class->getName(),
                $method->getName(),
                $guard,
            ));
        }

        return $guard;
    }

    /**
     * The generator overrides for a rule: the method its attribute names, or
     * `<rule>Generators()` when the class has one.
     *
     * @param \ReflectionClass<object> $class
     *
     * @return array<string, ArbitraryInterface>
     */
    private function overrides(\ReflectionClass $class, \ReflectionMethod $method): array
    {
        $named = $method->getAttributes(Rule::class)[0]->newInstance()->generators;
        $provider = $named ?? $method->getName() . 'Generators';

        if (!$class->hasMethod($provider)) {
            if ($named !== null) {
                throw new \InvalidArgumentException(sprintf('Gen::rules(): rule %s::%s() names generators "%s", which does not exist', $class->getName(), $method->getName(), $named));
            }

            return [];
        }

        $source = $class->getMethod($provider);

        if (!$source->isPublic() || !$source->isStatic()) {
            throw new \InvalidArgumentException(sprintf('Gen::rules(): generators %s::%s() must be a public static method', $class->getName(), $provider));
        }

        $overrides = $source->invoke(null);

        if (!is_array($overrides)) {
            throw new \InvalidArgumentException(sprintf('Gen::rules(): generators %s::%s() must return an array, got %s', $class->getName(), $provider, get_debug_type($overrides)));
        }

        $generators = [];

        /** @var mixed $generator */
        foreach ($overrides as $name => $generator) {
            if (!is_string($name) || !$generator instanceof ArbitraryInterface) {
                throw new \InvalidArgumentException(sprintf('Gen::rules(): generators %s::%s() must map parameter names to generators', $class->getName(), $provider));
            }

            $generators[$name] = $generator;
        }

        return $generators;
    }
}
