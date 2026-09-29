<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;

/**
 * One of the ways a rule-based machine can be declared wrong, for
 * `Gen::rules()` to refuse by name.
 */
final class UnnamedGenerators
{
    #[Rule]
    public function step(int $n): void {}

    /** @return list<\Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public static function stepGenerators(): array
    {
        return [Gen::int()];
    }
}
