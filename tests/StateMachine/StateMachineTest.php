<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine;

use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\PostconditionViolation;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\BuggyStack;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\PopCommand;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\PushCommand;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Stack;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(StateMachine::class)]
final class StateMachineTest
{
    public function runsEveryCommandAndThreadsTheModelForAValidSequence(): void
    {
        $sequence = new CommandSequence([], [new PushCommand(1), new PushCommand(2), new PopCommand()]);
        $stack = null;

        StateMachine::check($sequence, static function () use (&$stack): Stack {
            return $stack = new Stack();
        });

        Assert::instanceOf($stack, Stack::class);
        // Push, Push, Pop leaves exactly one item on a correct stack.
        Assert::same($stack->size(), 1);
    }

    public function callsTheSystemFactoryExactlyOnce(): void
    {
        $sequence = new CommandSequence([], [new PushCommand(1), new PopCommand()]);
        $calls = 0;

        StateMachine::check($sequence, static function () use (&$calls): Stack {
            ++$calls;

            return new Stack();
        });

        Assert::same($calls, 1);
    }

    public function throwsPostconditionViolationWhenTheSystemMisbehaves(): void
    {
        $sequence = new CommandSequence([], [new PushCommand(1), new PushCommand(2), new PopCommand()]);

        $thrown = null;

        try {
            StateMachine::check($sequence, static fn(): BuggyStack => new BuggyStack());
        } catch (PostconditionViolation $exception) {
            $thrown = $exception;
        }

        Assert::instanceOf($thrown, PostconditionViolation::class);
        Assert::same($thrown->step, 3);
        Assert::instanceOf($thrown->command, PopCommand::class);
        Assert::same($thrown->trace, ['Push(1)', 'Push(2)', 'Pop()']);
        // Pre-state model at the Pop was [1, 2]; the FIFO bug returns 1, not 2.
        Assert::same($thrown->model, [1, 2]);
        Assert::same($thrown->result, 1);
    }

    public function skipsCommandsWhosePreconditionNoLongerHolds(): void
    {
        // The trailing Pop is applied at an empty model: its precondition is
        // false, so the runner must skip it instead of popping an empty stack
        // (which would throw). A shrink that dropped an earlier Push produces
        // exactly this shape.
        $sequence = new CommandSequence([], [new PushCommand(1), new PopCommand(), new PopCommand()]);
        $stack = null;

        StateMachine::check($sequence, static function () use (&$stack): Stack {
            return $stack = new Stack();
        });

        Assert::instanceOf($stack, Stack::class);
        Assert::same($stack->size(), 0);
    }

    public function continuesRunningCommandsAfterSkippingAnInapplicableOne(): void
    {
        // The leading Pop is inapplicable at the empty model and must be skipped,
        // not abort the run: the following Push still has to execute.
        $sequence = new CommandSequence([], [new PopCommand(), new PushCommand(1)]);
        $stack = null;

        StateMachine::check($sequence, static function () use (&$stack): Stack {
            return $stack = new Stack();
        });

        Assert::instanceOf($stack, Stack::class);
        Assert::same($stack->size(), 1);
    }

    public function acceptsAnEmptySequence(): void
    {
        $calls = 0;

        StateMachine::check(new CommandSequence([], []), static function () use (&$calls): Stack {
            ++$calls;

            return new Stack();
        });

        Assert::same($calls, 1);
    }
}
