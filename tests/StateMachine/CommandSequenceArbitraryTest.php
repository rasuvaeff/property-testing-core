<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\StateMachine;

use Rasuvaeff\PropertyTesting\Arbitrary\CommandSequenceArbitrary;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\PostconditionViolation;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\BuggyStack;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\PopCommand;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\PushCommand;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CommandSequenceArbitrary::class)]
final class CommandSequenceArbitraryTest
{
    public function generatesOnlyCommandsApplicableInTheEvolvingModel(): void
    {
        $arbitrary = $this->stackCommands();

        // Replay each generated sequence against the model: every command must
        // have been applicable at its position (Pop only on a non-empty stack).
        for ($seed = 0; $seed < 50; ++$seed) {
            $sequence = $arbitrary->generate(new Random($seed))->value;
            Assert::instanceOf($sequence, CommandSequence::class);

            $model = [];
            foreach ($sequence->commands as $command) {
                Assert::true($command->preCondition($model));
                $model = $command->nextState($model);
            }
        }
    }

    public function stopsEarlyWhenNoCommandCanApply(): void
    {
        // Only a Pop generator against an empty initial model: no command is ever
        // applicable, so the sequence must terminate empty however long the drawn
        // length is — proving the early-stop break, not an infinite loop.
        $arbitrary = Gen::commands([], [Gen::constant(new PopCommand())], minLength: 0, maxLength: 50);

        for ($seed = 0; $seed < 30; ++$seed) {
            $sequence = $arbitrary->generate(new Random($seed))->value;
            Assert::instanceOf($sequence, CommandSequence::class);
            Assert::same($sequence->commands, []);
        }
    }

    public function shrinkTreeRemovesEachInteriorCommandIndividually(): void
    {
        $arbitrary = $this->stackCommands();
        $root = Trees::generateWhere($arbitrary, static fn(mixed $v): bool => $v instanceof CommandSequence && count($v->commands) >= 3);

        $original = $this->labels($root->value);
        $count = count($original);
        $middle = intdiv($count, 2);

        // The sequence with exactly the middle command removed must appear among
        // the direct shrink candidates — prefix halving alone cannot isolate it.
        $expected = array_values(array_merge(
            array_slice($original, 0, $middle),
            array_slice($original, $middle + 1),
        ));

        $candidates = array_map(
            $this->labels(...),
            Trees::childValues($root),
        );

        Assert::true(in_array($expected, $candidates, strict: true));
    }

    public function shrinkReachesTheMinimalFailingSequence(): void
    {
        $arbitrary = $this->stackCommands();
        $fails = $this->failsAgainstBuggyStack();

        $root = Trees::generateWhere($arbitrary, $fails);
        $minimal = Trees::descendWhile($root, $fails);

        Assert::true($fails($minimal->value));
        // FIFO-vs-LIFO needs two distinct values pushed then popped: the minimal
        // counterexample is exactly Push, Push, Pop (three commands).
        Assert::instanceOf($minimal->value, CommandSequence::class);
        Assert::same(count($minimal->value->commands), 3);
    }

    public function generatesExactlyTheDrawnLengthWhenEveryCommandApplies(): void
    {
        // Push always applies, so a degenerate [1, 1] length range must yield
        // exactly one command — guards the generation loop bound and a maxLength
        // of 1 being valid rather than rejected.
        $arbitrary = Gen::commands(
            [],
            [Gen::map(Gen::intBetween(0, 5), static fn(mixed $v): PushCommand => new PushCommand((int) $v))],
            minLength: 1,
            maxLength: 1,
        );

        for ($seed = 0; $seed < 20; ++$seed) {
            $sequence = $arbitrary->generate(new Random($seed))->value;
            Assert::instanceOf($sequence, CommandSequence::class);
            Assert::same(count($sequence->commands), 1);
        }
    }

    public function reindexesNonListGeneratorArrays(): void
    {
        // A gap-keyed generator array must be reindexed to a list; otherwise the
        // integer draw into it misses and generation blows up.
        $pushGen = Gen::map(Gen::intBetween(0, 5), static fn(mixed $v): PushCommand => new PushCommand((int) $v));
        $arbitrary = Gen::commands([], [7 => $pushGen], minLength: 1, maxLength: 1);

        $sequence = $arbitrary->generate(new Random(0))->value;

        Assert::instanceOf($sequence, CommandSequence::class);
        Assert::same(count($sequence->commands), 1);
    }

    #[ExpectException(GenerationExhausted::class)]
    public function throwsGenerationExhaustedWhenMinLengthUnreachable(): void
    {
        // Pop never applies to the empty model, so no command can be appended —
        // the minimum length of 1 is unreachable and generation is exhausted
        // rather than returning a too-short sequence.
        $arbitrary = Gen::commands([], [Gen::constant(new PopCommand())], minLength: 1, maxLength: 5);

        $arbitrary->generate(new Random(1));
    }

    public function shrinkCanRemoveDownToExactlyMinLength(): void
    {
        $arbitrary = Gen::commands(
            [],
            [Gen::map(Gen::intBetween(0, 5), static fn(mixed $v): PushCommand => new PushCommand((int) $v))],
            minLength: 2,
            maxLength: 8,
        );
        // A length-4 root offers a block removal down to exactly minLength (2);
        // that candidate must be present (boundary of the minLength guard).
        $root = Trees::generateWhere(
            $arbitrary,
            static fn(mixed $v): bool => $v instanceof CommandSequence && count($v->commands) === 4,
        );

        $reachesMin = false;
        foreach (Trees::childValues($root) as $value) {
            if ($value instanceof CommandSequence && count($value->commands) === 2) {
                $reachesMin = true;

                break;
            }
        }

        Assert::true($reachesMin);
    }

    public function shrinkNeverGoesBelowMinLength(): void
    {
        $arbitrary = Gen::commands(
            [],
            [Gen::map(Gen::intBetween(0, 5), static fn(mixed $v): PushCommand => new PushCommand((int) $v))],
            minLength: 2,
            maxLength: 6,
        );
        $root = $arbitrary->generate(new Random(7));

        foreach (Trees::valuesToDepth($root, 3) as $value) {
            Assert::instanceOf($value, CommandSequence::class);
            Assert::true(count($value->commands) >= 2);
        }
    }

    public function simplifiesIndividualCommandParametersKeepingTheLength(): void
    {
        $arbitrary = Gen::commands(
            [],
            [Gen::map(Gen::intBetween(1, 9), static fn(mixed $v): PushCommand => new PushCommand((int) $v))],
            minLength: 3,
            maxLength: 3,
        );
        $root = Trees::generateWhere(
            $arbitrary,
            static fn(mixed $v): bool => $v instanceof CommandSequence
                && array_sum(array_map(static fn(PushCommand $c): int => $c->value, $v->commands)) > 0,
        );

        $rootLabels = $this->labels($root->value);

        // A same-length candidate with different labels proves a command's own
        // parameter shrank (e.g. Push(9) -> Push(0)), not just a dropped step.
        $found = false;
        foreach (Trees::childValues($root) as $value) {
            Assert::instanceOf($value, CommandSequence::class);
            if (count($value->commands) === count($rootLabels) && $this->labels($value) !== $rootLabels) {
                $found = true;

                break;
            }
        }

        Assert::true($found);
    }

    public function pickBudgetIsExactlyTwentyAttemptsPerStep(): void
    {
        // Pop never applies to the empty model: a single required step must
        // draw exactly MAX_PICK_ATTEMPTS (20) candidates before giving up —
        // an off-by-one loop bound or start value would draw a 21st.
        $counting = new class implements ArbitraryInterface {
            public int $draws = 0;

            #[\Override]
            public function generate(Random $random): Shrinkable
            {
                ++$this->draws;

                return Shrinkable::leaf(new PopCommand());
            }
        };
        $arbitrary = new CommandSequenceArbitrary([], [$counting], minLength: 1, maxLength: 1);

        try {
            $arbitrary->generate(new Random(1));

            Assert::fail('expected GenerationExhausted');
        } catch (GenerationExhausted) {
        }

        Assert::same($counting->draws, 20);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyGeneratorList(): void
    {
        new CommandSequenceArbitrary([], []);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonArbitraryGenerator(): void
    {
        new CommandSequenceArbitrary([], ['not an arbitrary']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeMinLength(): void
    {
        new CommandSequenceArbitrary([], [Gen::constant(new PopCommand())], minLength: -1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAMaxLengthBelowOne(): void
    {
        new CommandSequenceArbitrary([], [Gen::constant(new PopCommand())], maxLength: 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsMinLengthAboveMaxLength(): void
    {
        new CommandSequenceArbitrary([], [Gen::constant(new PopCommand())], minLength: 3, maxLength: 2);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function throwsWhenAGeneratorProducesANonCommand(): void
    {
        $arbitrary = Gen::commands([], [Gen::intBetween(0, 5)], minLength: 1, maxLength: 1);

        $arbitrary->generate(new Random(0));
    }

    public function variantCountIsTheNumberOfCommandGenerators(): void
    {
        Assert::same($this->stackCommands()->variantCount(), 2);
    }

    public function restrictingTheCommandsKeepsTheModelAndBothLengthBounds(): void
    {
        // A swarm narrows the alphabet and nothing else: the restricted copy
        // still starts from the same model and still owes exactly two steps.
        $pushOnly = Gen::commands([], [
            Gen::map(Gen::intBetween(0, 5), static fn(mixed $v): PushCommand => new PushCommand((int) $v)),
            Gen::constant(new PopCommand()),
        ], minLength: 2, maxLength: 2)->withVariants([0]);

        for ($seed = 0; $seed < 20; ++$seed) {
            $sequence = $pushOnly->generate(new Random($seed))->value;
            Assert::instanceOf($sequence, CommandSequence::class);
            Assert::same(count($sequence->commands), 2);

            foreach ($sequence->commands as $command) {
                Assert::instanceOf($command, PushCommand::class);
            }
        }
    }

    #[ExpectException(GenerationExhausted::class)]
    public function restrictingAwayTheOnlyApplicableCommandStarvesTheMinimumLength(): void
    {
        // Pop alone cannot start from an empty stack, so the minimum is
        // unreachable — and an unreachable minimum throws here exactly as it
        // does when the model itself starves the generator. Worth pinning:
        // this is the sharp edge of swarming over Gen::commands().
        Gen::commands([], [
            Gen::map(Gen::intBetween(0, 5), static fn(mixed $v): PushCommand => new PushCommand((int) $v)),
            Gen::constant(new PopCommand()),
        ], minLength: 1)->withVariants([1])->generate(new Random(1));
    }

    public function rejectsAVariantIndexOutsideTheCommandGenerators(): void
    {
        try {
            $this->stackCommands()->withVariants([2]);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Variant 2 is outside the 2 command generators of this generator');
        }
    }

    private function stackCommands(): CommandSequenceArbitrary
    {
        return Gen::commands([], [
            Gen::map(Gen::intBetween(0, 5), static fn(mixed $v): PushCommand => new PushCommand((int) $v)),
            Gen::constant(new PopCommand()),
        ]);
    }

    /**
     * @return \Closure(mixed): bool
     */
    private function failsAgainstBuggyStack(): \Closure
    {
        return static function (mixed $sequence): bool {
            if (!$sequence instanceof CommandSequence) {
                return false;
            }

            try {
                StateMachine::check($sequence, static fn(): BuggyStack => new BuggyStack());

                return false;
            } catch (PostconditionViolation) {
                return true;
            }
        };
    }

    /**
     * @return list<string>
     */
    private function labels(mixed $sequence): array
    {
        Assert::instanceOf($sequence, CommandSequence::class);

        return array_map(static fn(object $command): string => (string) $command, $sequence->commands);
    }
}
