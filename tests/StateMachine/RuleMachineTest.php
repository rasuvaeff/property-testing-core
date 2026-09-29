<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\RuleMachine;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\StateMachine\RuleSequence;
use Rasuvaeff\PropertyTesting\StateMachine\RuleStep;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\BuggyStack;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\InstanceGenerators;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\MissingGuard;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\MissingNamedGenerators;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\NamedGenerators;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\NonArrayGenerators;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\NoRules;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\ParameterisedInvariant;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\PrivateGuard;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\PrivateRule;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\StaticRule;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\TwoOverrides;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Machines\UnnamedGenerators;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\Stack;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\StackMachine;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * `Gen::rules()`: a machine class read into step generators, and the
 * sequences the engine generates, runs and shrinks over it.
 */
#[Test]
#[Covers(RuleMachine::class)]
#[Covers(Gen::class)]
final class RuleMachineTest
{
    public function generatesSequencesOfRuleSteps(): void
    {
        $arbitrary = Gen::rules(StackMachine::class, maxLength: 10);
        $random = new Random(3);
        $rules = [];

        for ($i = 0; $i < 30; ++$i) {
            $sequence = $arbitrary->generate($random)->value;
            Assert::instanceOf($sequence, RuleSequence::class);
            Assert::true(count($sequence->steps) <= 10);

            foreach ($sequence->steps as $step) {
                Assert::instanceOf($step, RuleStep::class);
                $rules[$step->rule] = true;

                if ($step->rule === 'push') {
                    Assert::true($step->arguments['value'] >= 0 && $step->arguments['value'] <= 9);
                }
            }
        }

        $rules = array_keys($rules);
        sort($rules);
        Assert::same($rules, ['pop', 'push']);
    }

    public function aCorrectMachinePassesAndAnIncorrectOneIsFalsifiedAndShrunk(): void
    {
        $passing = $this->run(static fn(): StackMachine => new StackMachine(new Stack()));
        Assert::instanceOf($passing, Passed::class);

        $failing = $this->run(static fn(): StackMachine => new StackMachine(new BuggyStack()));
        Assert::instanceOf($failing, Falsified::class);

        $shrunk = $failing->counterExample()->shrunkArguments['sequence'];
        Assert::instanceOf($shrunk, RuleSequence::class);
        // The smallest FIFO-vs-LIFO witness: two distinct pushes and a pop.
        Assert::same((string) $shrunk, '[push(value: 0), push(value: 1), pop()]');
        Assert::string($failing->failure()->getMessage())->contains('popped 0, expected 1');
        // The sequence is a plain value: the factory lives in the body, not in it.
        Assert::same((string) unserialize(serialize($shrunk)), (string) $shrunk);
    }

    public function overridesNamedByTheAttributeWin(): void
    {
        $arbitrary = Gen::rules(NamedGenerators::class, minLength: 5, maxLength: 5);
        $random = new Random(1);

        for ($i = 0; $i < 20; ++$i) {
            foreach ($arbitrary->generate($random)->value->steps as $step) {
                Assert::true($step->arguments['n'] >= 0 && $step->arguments['n'] <= 3);
            }
        }
    }

    public function everyOverriddenParameterIsHonoured(): void
    {
        $arbitrary = Gen::rules(TwoOverrides::class, minLength: 3, maxLength: 3);
        $random = new Random(2);

        for ($i = 0; $i < 20; ++$i) {
            foreach ($arbitrary->generate($random)->value->steps as $step) {
                Assert::true($step->arguments['n'] >= 0 && $step->arguments['n'] <= 1);
                Assert::true($step->arguments['m'] >= 10 && $step->arguments['m'] <= 11);
            }
        }
    }

    #[DataProvider('invalidMachineProvider')]
    public function refusesAMisdeclaredMachineByName(string $class, string $message): void
    {
        try {
            Gen::rules($class);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), $message);
        }
    }

    public static function invalidMachineProvider(): iterable
    {
        yield 'not a class' => ['Nope\\Missing', 'Gen::rules(): "Nope\\Missing" is not a class'];
        yield 'no rules' => [NoRules::class, 'Gen::rules(): ' . NoRules::class . ' declares no #[Rule] method'];
        yield 'private rule' => [PrivateRule::class, 'Gen::rules(): ' . PrivateRule::class . '::step() must be a public instance method to be a rule'];
        yield 'static rule' => [StaticRule::class, 'Gen::rules(): ' . StaticRule::class . '::step() must be a public instance method to be a rule'];
        yield 'invariant with parameters' => [ParameterisedInvariant::class, 'Gen::rules(): invariant ' . ParameterisedInvariant::class . '::holds() must not take parameters'];
        yield 'missing guard' => [MissingGuard::class, 'Gen::rules(): rule ' . MissingGuard::class . '::step() names precondition "never", which is not a public instance method'];
        yield 'private guard' => [PrivateGuard::class, 'Gen::rules(): rule ' . PrivateGuard::class . '::step() names precondition "guard", which is not a public instance method'];
        yield 'missing named generators' => [MissingNamedGenerators::class, 'Gen::rules(): rule ' . MissingNamedGenerators::class . '::step() names generators "stepGens", which does not exist'];
        yield 'instance generators' => [InstanceGenerators::class, 'Gen::rules(): generators ' . InstanceGenerators::class . '::stepGenerators() must be a public static method'];
        yield 'non-array generators' => [NonArrayGenerators::class, 'Gen::rules(): generators ' . NonArrayGenerators::class . '::stepGenerators() must return an array, got string'];
        yield 'unnamed generators' => [UnnamedGenerators::class, 'Gen::rules(): generators ' . UnnamedGenerators::class . '::stepGenerators() must map parameter names to generators'];
    }

    private function run(\Closure $factory): PropertyResult
    {
        return (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'rules::property',
                name: 'property',
                generators: ['sequence' => Gen::rules(StackMachine::class, maxLength: 20)],
                parameterNames: ['sequence'],
                config: new PropertyConfig(runs: 100, seed: 42),
            ),
            new CallableTrialExecutor(static function (RuleSequence $sequence) use ($factory): void {
                $sequence->run($factory);
            }),
        );
    }
}
