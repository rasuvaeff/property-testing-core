<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine\Support;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\StateMachine\Invariant;
use Rasuvaeff\PropertyTesting\StateMachine\Precondition;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;

/**
 * One class per way a rule-based machine can be declared wrong, so
 * `Gen::rules()` can be shown refusing each by name.
 */
final class NoRules
{
    #[Invariant]
    public function holds(): void {}
}

final class PrivateRule
{
    #[Rule]
    private function step(): void {}
}

final class StaticRule
{
    #[Rule]
    public static function step(): void {}
}

final class ParameterisedInvariant
{
    #[Rule]
    public function step(): void {}

    #[Invariant]
    public function holds(int $n): void {}
}

final class MissingGuard
{
    #[Rule]
    #[Precondition('never')]
    public function step(): void {}
}

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

final class MissingNamedGenerators
{
    #[Rule(generators: 'stepGens')]
    public function step(int $n): void {}
}

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

final class NonArrayGenerators
{
    #[Rule]
    public function step(int $n): void {}

    public static function stepGenerators(): string
    {
        return 'no';
    }
}

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
