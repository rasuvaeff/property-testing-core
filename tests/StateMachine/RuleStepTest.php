<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine;

use Rasuvaeff\PropertyTesting\Internal\Invoke;
use Rasuvaeff\PropertyTesting\StateMachine\RuleStep;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Stack;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\StackMachine;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RuleStep::class)]
#[Covers(Invoke::class)]
final class RuleStepTest
{
    public function isApplicableBeforeAMachineExists(): void
    {
        $step = new RuleStep('pop', [], 'notEmpty');

        Assert::true($step->preCondition(null));
        Assert::null($step->nextState(null));
    }

    public function asksTheGuardOnceThereIsAMachine(): void
    {
        $machine = new StackMachine(new Stack());
        $step = new RuleStep('pop', [], 'notEmpty');

        Assert::false($step->preCondition($machine));
        $machine->push(1);
        Assert::true($step->preCondition($machine));
    }

    public function aStepWithoutAGuardIsAlwaysApplicable(): void
    {
        Assert::true((new RuleStep('push', ['value' => 1]))->preCondition(new StackMachine(new Stack())));
    }

    public function runsTheRuleWithItsArgumentsThenEveryInvariant(): void
    {
        $machine = new StackMachine(new Stack());
        $step = new RuleStep('push', ['value' => 7], null, ['sizeMatches']);

        Assert::null($step->run($machine, $machine));
        Assert::same($machine->trace, ['push:7']);
        Assert::same($machine->invariantChecks, 1);
        Assert::true($step->postCondition($machine, null));
    }

    public function refusesToRunAgainstANonObject(): void
    {
        try {
            (new RuleStep('push', ['value' => 1]))->run(null, 'not a machine');

            Assert::fail('expected a LogicException');
        } catch (\LogicException $e) {
            Assert::same($e->getMessage(), 'A rule step runs against the machine object, got string');
        }
    }

    public function rendersAsACallWithNamedArguments(): void
    {
        Assert::same((string) new RuleStep('push', ['value' => 7, 'tag' => 'a']), 'push(value: 7, tag: "a")');
        Assert::same((string) new RuleStep('pop', []), 'pop()');
    }
}
