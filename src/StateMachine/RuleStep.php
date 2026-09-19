<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\StateMachine;

use Rasuvaeff\PropertyTesting\Internal\Invoke;
use Rasuvaeff\PropertyTesting\ValueRenderer;

/**
 * One step of a rule-based sequence: a {@see Rule} method and the arguments
 * drawn for it. It is the {@see Command} the engine's sequence generation and
 * shrinking work on, so a rule-based machine gets the same drop-and-simplify
 * descent a hand-written command sequence does.
 *
 * The model threaded through {@see StateMachine::check()} is the machine
 * object itself: at generation time there is no machine yet and every step
 * is applicable (the model is null), at run time the guard decides. The
 * step's body is the rule method — its exception is the failed
 * postcondition — followed by every invariant.
 *
 * @api
 */
final readonly class RuleStep implements Command
{
    /**
     * @param non-empty-string $rule The rule method.
     * @param array<string, mixed> $arguments By parameter name.
     * @param ?non-empty-string $precondition The guard method, when the rule has one.
     * @param list<non-empty-string> $invariants The invariant methods, run after the rule.
     */
    public function __construct(
        public string $rule,
        public array $arguments,
        public ?string $precondition = null,
        public array $invariants = [],
    ) {}

    /**
     * True before a machine exists (generation), the guard's answer once it does.
     */
    #[\Override]
    public function preCondition(mixed $model): bool
    {
        if (!is_object($model) || $this->precondition === null) {
            return true;
        }

        return (bool) Invoke::method($model, $this->precondition);
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    /**
     * Runs the rule, then every invariant, against $system — the machine.
     */
    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        if (!is_object($system)) {
            throw new \LogicException(sprintf('A rule step runs against the machine object, got %s', get_debug_type($system)));
        }

        Invoke::method($system, $this->rule, $this->arguments);

        foreach ($this->invariants as $invariant) {
            Invoke::method($system, $invariant);
        }

        return null;
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        return true;
    }

    #[\Override]
    public function __toString(): string
    {
        $pairs = array_map(
            static fn(mixed $value, string $name): string => $name . ': ' . ValueRenderer::render($value),
            $this->arguments,
            array_keys($this->arguments),
        );

        return $this->rule . '(' . implode(', ', $pairs) . ')';
    }
}
