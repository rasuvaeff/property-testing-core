<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines;

use Rasuvaeff\PropertyTesting\StateMachine\Rule;

/**
 * One of the ways a rule-based machine can be declared wrong, for
 * `Gen::rules()` to refuse by name.
 */
final class NonArrayGenerators
{
    #[Rule]
    public function step(int $n): void {}

    public static function stepGenerators(): string
    {
        return 'no';
    }
}
