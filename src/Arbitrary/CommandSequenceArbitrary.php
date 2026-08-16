<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\Swarmable;

/**
 * Generates valid {@see Command} sequences for stateful / model-based testing
 * and shrinks them by dropping and simplifying steps.
 *
 * Generation is model-aware: at each step a command generator is drawn at
 * random, and the produced command is appended only if its
 * {@see Command::preCondition()} holds in the running model, which is then
 * advanced via {@see Command::nextState()}. The sequence is therefore valid by
 * construction; if no applicable command is found within a bounded number of
 * attempts the sequence stops early.
 *
 * Shrinking removes whole blocks of commands (most aggressive first, down to a
 * single command so a failing step in the middle can be isolated) and then
 * simplifies individual commands through their own shrink trees. Dropped or
 * simplified sequences are not re-validated here — {@see \Rasuvaeff\PropertyTesting\StateMachine\StateMachine::check()}
 * skips any command whose precondition a change invalidated, keeping every
 * candidate sound.
 *
 * @implements Swarmable<CommandSequence>
 * @api
 */
final readonly class CommandSequenceArbitrary implements Swarmable
{
    /**
     * How many command generators to try per step before giving up and ending
     * the sequence (guards against a state in which no command applies).
     */
    private const int MAX_PICK_ATTEMPTS = 20;

    /**
     * @var non-empty-list<ArbitraryInterface<Command>>
     */
    private array $commandGenerators;

    /**
     * @param array<array-key, ArbitraryInterface> $commandGenerators Each must produce a {@see Command}.
     */
    public function __construct(
        private mixed $initialModel,
        array $commandGenerators,
        private int $minLength = 0,
        private int $maxLength = 100,
    ) {
        if ($commandGenerators === []) {
            throw new \InvalidArgumentException('At least one command generator is required');
        }
        foreach ($commandGenerators as $generator) {
            /** @var mixed $generator */
            if (!$generator instanceof ArbitraryInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Command generators must be ArbitraryInterface, got %s',
                    get_debug_type($generator),
                ));
            }
        }
        if ($minLength < 0) {
            throw new \InvalidArgumentException('Minimum length must be greater than or equal to 0');
        }
        if ($maxLength < 1) {
            throw new \InvalidArgumentException('Maximum length must be greater than or equal to 1');
        }
        if ($minLength > $maxLength) {
            throw new \InvalidArgumentException('Minimum length must be less than or equal to maximum length');
        }

        /** @var non-empty-list<ArbitraryInterface<Command>> $validated */
        $validated = array_values($commandGenerators);
        $this->commandGenerators = $validated;
    }

    /**
     * @return Shrinkable<CommandSequence>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $length = $random->int($this->minLength, $this->maxLength);

        /** @var list<Shrinkable<Command>> $commands */
        $commands = [];
        /** @var mixed $model */
        $model = $this->initialModel;

        while (count($commands) < $length) {
            $picked = $this->pickApplicableCommand($random, $model);

            if (!$picked instanceof Shrinkable) {
                break;
            }

            $command = $picked->value;

            $commands[] = $picked;
            /** @var mixed $model */
            $model = $command->nextState($model);
        }

        if (count($commands) < $this->minLength) {
            throw new GenerationExhausted(
                'Gen::commands()',
                self::MAX_PICK_ATTEMPTS,
                sprintf(
                    'only %d of a minimum %d command(s) applied to the model; no applicable command was found to reach the minimum length',
                    count($commands),
                    $this->minLength,
                ),
            );
        }

        return $this->tree($commands);
    }

    #[\Override]
    public function variantCount(): int
    {
        return count($this->commandGenerators);
    }

    /**
     * The restricted copy keeps the initial model and both length bounds — a
     * swarm narrows the command alphabet and nothing else. That is also where
     * its sharpest edge is: with a $minLength above zero, a subset from which
     * no applicable command reaches that length throws
     * {@see GenerationExhausted} exactly as the unrestricted generator does
     * when the model starves it. That is the contract, not an accident — a
     * sequence shorter than its minimum has never been a valid result here,
     * and silently returning one would be the worse answer.
     *
     * @param list<int> $indices Variant positions to keep, each in `[0, variantCount() - 1]`.
     *
     * @throws \InvalidArgumentException When an index falls outside the command generators.
     */
    #[\Override]
    public function withVariants(array $indices): self
    {
        return new self(
            $this->initialModel,
            array_map(
                fn(int $index): ArbitraryInterface => $this->commandGenerators[$index] ?? throw new \InvalidArgumentException(sprintf(
                    'Variant %d is outside the %d command generators of this generator',
                    $index,
                    count($this->commandGenerators),
                )),
                $indices,
            ),
            $this->minLength,
            $this->maxLength,
        );
    }

    /**
     * Draw command generators until one yields a command applicable in the
     * current model, or the attempt budget runs out.
     *
     * @return ?Shrinkable<Command>
     */
    private function pickApplicableCommand(Random $random, mixed $model): ?Shrinkable
    {
        $lastIndex = count($this->commandGenerators) - 1;

        for ($attempt = 0; $attempt < self::MAX_PICK_ATTEMPTS; ++$attempt) {
            $candidate = $this->commandGenerators[$random->int(0, $lastIndex)]->generate($random);
            $command = $candidate->value;

            if (!$command instanceof Command) {
                throw new \InvalidArgumentException(sprintf(
                    'Command generators must produce %s instances, got %s',
                    Command::class,
                    get_debug_type($command),
                ));
            }

            if ($command->preCondition($model)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<Shrinkable<Command>> $commands
     *
     * @return Shrinkable<CommandSequence>
     */
    private function tree(array $commands): Shrinkable
    {
        $sequence = new CommandSequence($this->initialModel, array_map(
            static fn(Shrinkable $command): Command => $command->value,
            $commands,
        ));

        return Shrinkable::of($sequence, function () use ($commands): \Generator {
            $count = count($commands);

            // 1. Length first: remove contiguous blocks (whole list, halves, ...,
            //    down to a single command). Single-command removal isolates a
            //    failing step in the middle, which prefix halving alone cannot.
            for ($blockSize = $count; $blockSize >= 1; $blockSize = intdiv($blockSize, 2)) {
                if ($count - $blockSize < $this->minLength) {
                    continue;
                }

                for ($offset = 0; $offset + $blockSize <= $count; $offset += $blockSize) {
                    yield $this->tree(array_merge(
                        array_slice($commands, 0, $offset),
                        array_slice($commands, $offset + $blockSize),
                    ));
                }
            }

            // 2. Then simplify each command through its own tree, length fixed.
            foreach ($commands as $index => $command) {
                foreach ($command->shrinks() as $smaller) {
                    yield $this->tree(array_replace($commands, [$index => $smaller]));
                }
            }
        });
    }
}
