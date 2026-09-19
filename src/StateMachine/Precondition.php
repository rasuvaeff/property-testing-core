<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\StateMachine;

/**
 * Names the guard of a {@see Rule}: a public method of the machine returning
 * bool, evaluated against the machine's current state before the step runs.
 * A step whose guard is false is skipped, not failed — the same
 * skip-on-replay contract {@see StateMachine::check()} applies to a
 * {@see Command} whose precondition a dropped step invalidated.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Precondition
{
    /**
     * @param non-empty-string $method
     */
    public function __construct(
        public string $method,
    ) {}
}
