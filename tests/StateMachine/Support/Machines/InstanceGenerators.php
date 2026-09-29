<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;

/**
 * One of the ways a rule-based machine can be declared wrong, for
 * `Gen::rules()` to refuse by name.
 */
final class InstanceGenerators
{
    #[Rule]
    public function step(int $n): void {}

    /** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public function stepGenerators(): array
    {
        return ['n' => Gen::int()];
    }
}
