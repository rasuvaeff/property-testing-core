<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\StateMachine\Invariant;
use Rasuvaeff\PropertyTesting\StateMachine\Precondition;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;
use Rasuvaeff\PropertyTesting\StateMachine\RuleSequence;

/**
 * Stateful testing with the rule-based façade: one class, whose #[Rule]
 * methods are the steps, whose #[Invariant] holds after every step, and
 * whose own fields are the model. Against a correct stack the property
 * passes; against one that pops in FIFO order it is falsified and the
 * sequence shrinks to the shortest witness.
 */

interface Stack
{
    public function push(int $value): void;

    public function pop(): int;

    public function size(): int;
}

final class LifoStack implements Stack
{
    /** @var list<int> */
    private array $items = [];

    public function push(int $value): void
    {
        $this->items[] = $value;
    }

    public function pop(): int
    {
        return array_pop($this->items) ?? throw new UnderflowException('empty');
    }

    public function size(): int
    {
        return count($this->items);
    }
}

final class FifoStack implements Stack
{
    /** @var list<int> */
    private array $items = [];

    public function push(int $value): void
    {
        $this->items[] = $value;
    }

    public function pop(): int
    {
        // BUG: oldest first.
        return array_shift($this->items) ?? throw new UnderflowException('empty');
    }

    public function size(): int
    {
        return count($this->items);
    }
}

final class StackMachine
{
    /** @var list<int> */
    private array $expected = [];

    public function __construct(private readonly Stack $sut) {}

    #[Rule]
    public function push(int $value): void
    {
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
        $expected = array_pop($this->expected);
        $actual = $this->sut->pop();

        if ($actual !== $expected) {
            throw new RuntimeException(sprintf('popped %d, expected %d', $actual, $expected));
        }
    }

    public function notEmpty(): bool
    {
        return $this->expected !== [];
    }

    #[Invariant]
    public function sizeMatches(): void
    {
        if ($this->sut->size() !== count($this->expected)) {
            throw new RuntimeException('size mismatch');
        }
    }
}

$definition = new PropertyDefinition(
    id: 'examples::stackBehavesLikeItsModel',
    name: 'stackBehavesLikeItsModel',
    generators: ['sequence' => Gen::rules(StackMachine::class, maxLength: 20)],
    parameterNames: ['sequence'],
    config: new PropertyConfig(runs: 100, seed: 42),
);

$runner = new PropertyRunner();

foreach (['LifoStack' => LifoStack::class, 'FifoStack' => FifoStack::class] as $name => $class) {
    $result = $runner->run($definition, new CallableTrialExecutor(
        static function (RuleSequence $sequence) use ($class): void {
            $sequence->run(static fn(): StackMachine => new StackMachine(new $class()));
        },
    ));

    echo "== {$name} ==\n";

    if ($result instanceof Passed) {
        printf("passed %d sequences\n", $result->statistics->checks);
    } elseif ($result instanceof Falsified) {
        $example = $result->counterExample();
        printf("falsified; shrunk sequence: %s\n", (string) $example->shrunkArguments['sequence']);
        printf("failure: %s\n", $example->failure?->getMessage());
    }

    echo "\n";
}
