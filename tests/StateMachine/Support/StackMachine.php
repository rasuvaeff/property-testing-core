<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine\Support;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\StateMachine\Invariant;
use Rasuvaeff\PropertyTesting\StateMachine\Precondition;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;

/**
 * The rule-based rendering of the stack example: the model is the machine's
 * own `$expected` list, the rules drive the system under test, and the
 * invariant compares sizes after every step.
 */
final class StackMachine
{
    /** @var list<int> */
    private array $expected = [];

    /** @var list<string> */
    public array $trace = [];

    public int $invariantChecks = 0;

    public function __construct(
        private readonly StackSut $sut,
    ) {}

    #[Rule]
    public function push(int $value): void
    {
        $this->trace[] = 'push:' . $value;
        $this->sut->push($value);
        $this->expected[] = $value;
    }

    /** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public static function pushGenerators(): array
    {
        return ['value' => Gen::intBetween(0, 9)];
    }

    #[Rule]
    #[Precondition('notEmpty')]
    public function pop(): void
    {
        $this->trace[] = 'pop';
        $expected = array_pop($this->expected);
        $actual = $this->sut->pop();

        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf('popped %d, expected %d', $actual, $expected));
        }
    }

    public function notEmpty(): bool
    {
        return $this->expected !== [];
    }

    #[Invariant]
    public function sizeMatches(): void
    {
        ++$this->invariantChecks;

        if ($this->sut->size() !== count($this->expected)) {
            throw new \RuntimeException('size mismatch');
        }
    }
}
