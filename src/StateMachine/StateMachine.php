<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\StateMachine;

use Closure;

/**
 * Drives a {@see CommandSequence} against a fresh system under test for
 * stateful / model-based testing.
 *
 * Call it from a property body with the generated sequence and a factory that
 * builds a fresh system per run:
 *
 * ```php
 * #[Property]
 * public function stackMatchesModel(CommandSequence $sequence): void
 * {
 *     StateMachine::check($sequence, static fn(): Stack => new Stack());
 * }
 * ```
 *
 * For each command it re-checks {@see Command::preCondition()} against the
 * running model and skips the command if it no longer holds — shrinking may have
 * dropped an earlier step that a later precondition depended on, so a replayed
 * sequence stays sound without the arbitrary re-validating every candidate. A
 * passing precondition runs the command, asserts {@see Command::postCondition()}
 * (throwing {@see PostconditionViolation} on failure), then advances the model.
 *
 * @api
 */
final class StateMachine
{
    private function __construct()
    {
        // Static entry point; not instantiable.
    }

    /**
     * @param Closure(): mixed $system Factory returning a fresh system under test.
     *
     * @throws PostconditionViolation
     */
    public static function check(CommandSequence $sequence, Closure $system): void
    {
        /** @var mixed $model */
        $model = $sequence->initialModel;
        /** @var mixed $system_ */
        $system_ = ($system)();

        $trace = [];
        $step = 0;

        foreach ($sequence->commands as $command) {
            if (!$command->preCondition($model)) {
                continue;
            }

            ++$step;
            $trace[] = (string) $command;

            /** @var mixed $result */
            $result = $command->run($model, $system_);

            if (!$command->postCondition($model, $result)) {
                throw new PostconditionViolation($trace, $step, $command, $model, $result);
            }

            /** @var mixed $model */
            $model = $command->nextState($model);
        }
    }
}
