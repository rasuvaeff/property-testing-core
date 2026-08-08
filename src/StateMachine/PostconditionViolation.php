<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\StateMachine;

use RuntimeException;

/**
 * Thrown by {@see StateMachine::check()} when a command's
 * {@see Command::postCondition()} returns false.
 *
 * Carries the executed trace (command labels up to and including the failing
 * one), the failing command, and the pre-state model and observed result, so
 * the property runner surfaces exactly which step of the sequence broke.
 *
 * @api
 */
final class PostconditionViolation extends RuntimeException
{
    /**
     * @param list<string> $trace Labels of the commands executed up to and including the failing one.
     */
    public function __construct(
        public readonly array $trace,
        public readonly int $step,
        public readonly Command $command,
        public readonly mixed $model,
        public readonly mixed $result,
    ) {
        parent::__construct(sprintf(
            'Postcondition failed at step %d for command %s; sequence: [%s]',
            $step,
            (string) $command,
            implode(', ', $trace),
        ));
    }
}
