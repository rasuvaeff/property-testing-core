<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\StateMachine;

use Closure;
use Rasuvaeff\PropertyTesting\Internal\Invoke;

/**
 * A generated sequence of {@see RuleStep}s over a rule-based machine — what
 * {@see \Rasuvaeff\PropertyTesting\Gen::rules()} produces and the property
 * body runs against a machine it builds:
 *
 *     #[Property(runs: 200)]
 *     public function queueBehavesLikeAList(RuleSequence $sequence): void
 *     {
 *         $sequence->run(static fn (): QueueMachine => new QueueMachine(new Queue()));
 *     }
 *
 * The factory is handed to {@see run()} rather than kept in the value — the
 * split {@see StateMachine::check()} makes — so the sequence stays a plain
 * value: it renders as the trace, serializes inside a result, and never
 * carries a closure into the corpus.
 *
 * {@see run()} builds a fresh machine every time, so shrink trials never see
 * state a previous trial left behind, checks the invariants once, then walks
 * the steps: a step whose {@see Precondition} is false in the machine's
 * current state is skipped, every other step runs its rule and then every
 * invariant. The first exception ends the run and falsifies the property.
 *
 * @api
 */
final readonly class RuleSequence implements \Stringable
{
    /**
     * @param list<RuleStep> $steps The steps, in the order they were generated.
     * @param list<non-empty-string> $invariants The invariant methods, checked before the first step.
     */
    public function __construct(
        public array $steps,
        public array $invariants = [],
    ) {}

    /**
     * Execute the sequence against the machine $factory builds.
     *
     * @param Closure(): object $factory A fresh machine, system under test included.
     */
    public function run(Closure $factory): void
    {
        $machine = $factory();

        foreach ($this->invariants as $invariant) {
            Invoke::method($machine, $invariant);
        }

        StateMachine::check(new CommandSequence($machine, $this->steps), static fn(): object => $machine);
    }

    /**
     * The trace: every step as a call, in order.
     */
    #[\Override]
    public function __toString(): string
    {
        return '[' . implode(', ', $this->steps) . ']';
    }
}
