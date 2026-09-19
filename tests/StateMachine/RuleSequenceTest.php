<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine;

use Rasuvaeff\PropertyTesting\StateMachine\RuleSequence;
use Rasuvaeff\PropertyTesting\StateMachine\RuleStep;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\BuggyStack;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Stack;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\StackMachine;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RuleSequence::class)]
final class RuleSequenceTest
{
    public function buildsAFreshMachinePerRunAndChecksInvariantsFirst(): void
    {
        $built = [];
        $sequence = new RuleSequence(
            static function () use (&$built): StackMachine {
                $machine = new StackMachine(new Stack());
                $built[] = $machine;

                return $machine;
            },
            [new RuleStep('push', ['value' => 1], null, ['sizeMatches']), new RuleStep('pop', [], 'notEmpty', ['sizeMatches'])],
            ['sizeMatches'],
        );

        $sequence->run();
        $sequence->run();

        Assert::same(count($built), 2);
        Assert::same($built[0]->trace, ['push:1', 'pop']);
        // Once before the first step, once after each of the two.
        Assert::same($built[0]->invariantChecks, 3);
        Assert::same($built[1]->trace, ['push:1', 'pop']);
    }

    public function skipsAStepWhoseGuardIsFalse(): void
    {
        $machines = [];
        $sequence = new RuleSequence(
            static function () use (&$machines): StackMachine {
                return $machines[] = new StackMachine(new Stack());
            },
            [new RuleStep('pop', [], 'notEmpty'), new RuleStep('push', ['value' => 2]), new RuleStep('pop', [], 'notEmpty')],
        );

        $sequence->run();

        Assert::same($machines[0]->trace, ['push:2', 'pop']);
    }

    public function anExceptionFromARuleEndsTheRun(): void
    {
        $machines = [];
        $sequence = new RuleSequence(
            static function () use (&$machines): StackMachine {
                return $machines[] = new StackMachine(new BuggyStack());
            },
            [new RuleStep('push', ['value' => 1]), new RuleStep('push', ['value' => 2]), new RuleStep('pop', [], 'notEmpty'), new RuleStep('push', ['value' => 3])],
        );

        try {
            $sequence->run();

            Assert::fail('expected the buggy pop to throw');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'popped 1, expected 2');
        }

        Assert::same($machines[0]->trace, ['push:1', 'push:2', 'pop']);
    }

    public function rendersAsTheTraceOfItsSteps(): void
    {
        $sequence = new RuleSequence(static fn(): StackMachine => new StackMachine(new Stack()), [new RuleStep('push', ['value' => 1]), new RuleStep('pop', [])]);

        Assert::same((string) $sequence, '[push(value: 1), pop()]');
        Assert::same((string) new RuleSequence(static fn(): StackMachine => new StackMachine(new Stack()), []), '[]');
    }
}
