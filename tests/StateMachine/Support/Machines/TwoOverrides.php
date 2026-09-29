<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;

/**
 * A machine whose rule overrides both of its parameters.
 */
final class TwoOverrides
{
    #[Rule]
    public function step(int $n, int $m): void {}

    /** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public static function stepGenerators(): array
    {
        return ['n' => Gen::intBetween(0, 1), 'm' => Gen::intBetween(10, 11)];
    }
}
