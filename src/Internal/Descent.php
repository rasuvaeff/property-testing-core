<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Where one shrink descent stands: the current trees and draw tape, the notes
 * and failure of the last accepted candidate, what was counted on the way,
 * and the steps taken — which the runner reports whether the descent ran to
 * its end or was cut short by a cap or a budget.
 *
 * @internal Driven by the property runner.
 */
final class Descent
{
    /**
     * How many distinct enumeration errors a counterexample carries. A
     * shrinkable that throws usually throws the same way on every node it is
     * asked about; past a handful the list stops telling anything new.
     */
    public const int MAX_ERRORS = 10;

    public int $steps = 0;

    public int $trials = 0;

    public ?\Throwable $failure = null;

    /** @var list<array{name: string, index: int<0, max>}> */
    private array $acceptedSteps = [];

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param array<string, Shrinkable> $trees The arguments' shrink trees.
     * @param list<Shrinkable> $tape The recorded in-body draws.
     * @param array<string, mixed> $notes The notes of the run the trees and tape belong to.
     */
    public function __construct(
        public array $trees,
        public array $tape,
        public array $notes,
    ) {}

    /**
     * Moves the descent to an accepted candidate.
     *
     * @param array{name: string, index: int<0, max>} $step What a path records for it.
     * @param array<string, Shrinkable> $trees
     * @param list<Shrinkable> $tape
     * @param array<string, mixed> $notes
     */
    public function accept(array $step, array $trees, array $tape, array $notes, ?\Throwable $failure): void
    {
        $this->acceptedSteps[] = $step;
        $this->trees = $trees;
        $this->tape = $tape;
        $this->notes = $notes;
        $this->failure = $failure;
        ++$this->steps;
    }

    /**
     * The accepted steps as a replayable path.
     */
    public function path(): string
    {
        return ShrinkPath::format($this->acceptedSteps);
    }

    /**
     * Remembers why enumerating a node's candidates ended early, once per
     * distinct `Class: message`, up to {@see MAX_ERRORS}.
     */
    public function recordError(\Throwable $error): void
    {
        $line = $error::class . ': ' . $error->getMessage();

        if (count($this->errors) < self::MAX_ERRORS && !in_array($line, $this->errors, strict: true)) {
            $this->errors[] = $line;
        }
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
