<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;

/**
 * A machine whose rule names its generator method in the attribute.
 */
final class NamedGenerators
{
    /** @var list<int> */
    public array $seen = [];

    #[Rule(generators: 'smallInts')]
    public function step(int $n): void
    {
        $this->seen[] = $n;
    }

    /** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public static function smallInts(): array
    {
        return ['n' => Gen::intBetween(0, 3)];
    }
}
