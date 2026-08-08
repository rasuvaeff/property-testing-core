<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\StateMachine;

/**
 * A generated, valid-by-construction sequence of {@see Command}s together with
 * the initial model it was generated against.
 *
 * This is the value a {@see \Rasuvaeff\PropertyTesting\Gen::commands()} arbitrary
 * produces; the property body hands it to {@see StateMachine::check()}. It is
 * {@see \Stringable} so a falsified property renders the failing sequence as a
 * readable trace instead of `[N element(s)]`.
 *
 * @api
 */
final readonly class CommandSequence implements \Stringable
{
    /**
     * @param list<Command> $commands
     */
    public function __construct(
        public mixed $initialModel,
        public array $commands,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        // implode() stringifies each Command itself (Command extends Stringable)
        // and renders the empty sequence as '[]' without a special case.
        return '[' . implode(', ', $this->commands) . ']';
    }
}
