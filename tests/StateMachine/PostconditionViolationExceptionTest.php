<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine;

use Rasuvaeff\PropertyTesting\StateMachine\PostconditionViolationException;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\PopCommand;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PostconditionViolationException::class)]
final class PostconditionViolationExceptionTest
{
    public function carriesTheFailingStepAndTrace(): void
    {
        $command = new PopCommand();
        $exception = new PostconditionViolationException(['Push(1)', 'Push(2)', 'Pop()'], 3, $command, [1, 2], 1);

        Assert::same($exception->step, 3);
        Assert::same($exception->command, $command);
        Assert::same($exception->trace, ['Push(1)', 'Push(2)', 'Pop()']);
        Assert::same($exception->model, [1, 2]);
        Assert::same($exception->result, 1);
    }

    public function rendersAMessageNamingTheStepAndSequence(): void
    {
        $exception = new PostconditionViolationException(['Push(1)', 'Pop()'], 2, new PopCommand(), [1], 99);

        Assert::string($exception->getMessage())->contains('step 2');
        Assert::string($exception->getMessage())->contains('Pop()');
        Assert::string($exception->getMessage())->contains('[Push(1), Pop()]');
    }
}
