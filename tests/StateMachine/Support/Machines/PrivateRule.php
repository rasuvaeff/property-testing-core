<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines;

use Rasuvaeff\PropertyTesting\StateMachine\Rule;

/**
 * One of the ways a rule-based machine can be declared wrong, for
 * `Gen::rules()` to refuse by name.
 */
final class PrivateRule
{
    #[Rule]
    private function step(): void {}
}
