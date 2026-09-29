<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines;

use Rasuvaeff\PropertyTesting\StateMachine\Precondition;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;

/**
 * One of the ways a rule-based machine can be declared wrong, for
 * `Gen::rules()` to refuse by name.
 */
final class PrivateGuard
{
    #[Rule]
    #[Precondition('guard')]
    public function step(): void {}

    private function guard(): bool
    {
        return true;
    }
}
