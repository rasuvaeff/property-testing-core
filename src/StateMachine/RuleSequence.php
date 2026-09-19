<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\StateMachine;

use Closure;
use Rasuvaeff\PropertyTesting\Internal\Invoke;

/**
 * A generated sequence of {@see RuleStep}s over a rule-based machine — what
 * {@see \Rasuvaeff\PropertyTesting\Gen::rules()} produces and the property
 * body runs:
 *
 *     #[Property(runs: 200)]
 *     public function queueBehavesLikeAList(RuleSequence $sequence): void
 *     {
 *         $sequence->run();
 *     }
 *
 * {@see run()} builds a fresh machine from the factory every time, so shrink
 * trials never see state a previous trial left behind, checks the invariants
 * once, then walks the steps: a step whose {@see Precondition} is false in
 * the machine's current state is skipped, every other step runs its rule and
 * then every invariant. The first exception ends the run and falsifies the
 * property; the sequence renders as the trace that led there.
 *
 * @api
 */
final readonly class RuleSequence implements \Stringable
{
    /**
     * @param Closure(): object $factory Builds the machine, system under test included.
     * @param list<RuleStep> $steps
     * @param list<non-empty-string> $invariants The invariant methods, checked before the first step.
     */
    public function __construct(
        private Closure $factory,
        public array $steps,
        private array $invariants = [],
    ) {}

    /**
     * Execute the sequence against a fresh machine.
     */
    public function run(): void
    {
        $machine = ($this->factory)();

        foreach ($this->invariants as $invariant) {
            Invoke::method($machine, $invariant);
        }

        StateMachine::check(new CommandSequence($machine, $this->steps), static fn(): object => $machine);
    }

    #[\Override]
    public function __toString(): string
    {
        return '[' . implode(', ', $this->steps) . ']';
    }
}
