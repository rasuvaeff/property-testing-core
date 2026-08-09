<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine;

use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\PopCommand;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\PushCommand;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CommandSequence::class)]
final class CommandSequenceTest
{
    public function exposesTheInitialModelAndCommands(): void
    {
        $commands = [new PushCommand(1), new PopCommand()];
        $sequence = new CommandSequence([7], $commands);

        Assert::same($sequence->initialModel, [7]);
        Assert::same($sequence->commands, $commands);
    }

    public function rendersAnEmptySequenceAsEmptyBrackets(): void
    {
        Assert::same((string) new CommandSequence([], []), '[]');
    }

    public function rendersCommandsAsAReadableTrace(): void
    {
        $sequence = new CommandSequence([], [new PushCommand(3), new PushCommand(4), new PopCommand()]);

        Assert::same((string) $sequence, '[Push(3), Push(4), Pop()]');
    }
}
