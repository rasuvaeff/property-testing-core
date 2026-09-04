<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * Result of executing the property body once, in the engine's terms.
 *
 * Every framework reports failure its own way — Testo through a `TestResult`
 * status, PHPUnit through an assertion exception, a bare callable by throwing.
 * The adapter's {@see TrialExecutor} folds its way into this single shape so
 * the run/shrink loop never learns about framework types.
 *
 * @api
 */
final readonly class TrialOutcome
{
    /**
     * @param bool $passed The body returned normally: this run checked the input.
     * @param bool $discarded The run checked nothing — the input left the domain, or the environment refused to run it.
     * @param bool $skipped The discard came from the environment rather than from the input; kept out of the corpus prune.
     * @param ?\Throwable $failure What the body raised, when it raised anything.
     */
    private function __construct(
        private bool $passed,
        private bool $discarded,
        private bool $skipped,
        public ?\Throwable $failure,
    ) {}

    public static function passed(): self
    {
        return new self(passed: true, discarded: false, skipped: false, failure: null);
    }

    /**
     * @param ?\Throwable $failure The assertion or exception the body raised;
     *   null when the framework reports failure without a throwable.
     */
    public static function failed(?\Throwable $failure = null): self
    {
        return new self(passed: false, discarded: false, skipped: false, failure: $failure);
    }

    /**
     * The run was discarded via `Assume::that()` — neither a failure nor a
     * successful check. The input left the property's domain, which is a
     * statement about the input: a recorded regression that discards on replay
     * can never falsify again and is pruned.
     */
    public static function discarded(): self
    {
        return new self(passed: false, discarded: true, skipped: false, failure: null);
    }

    /**
     * The environment refused to run the body — a `markTestSkipped()` guarding
     * a missing dependency, a framework skip raised from a lifecycle hook. It
     * counts as a discard everywhere a discard counts, with one exception: it
     * says nothing about the input, so a recorded regression that only skipped
     * is kept rather than pruned. Deleting it would erase the counterexample
     * for every environment because one environment could not check it.
     */
    public static function skipped(): self
    {
        return new self(passed: false, discarded: true, skipped: true, failure: null);
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function isFailed(): bool
    {
        return !$this->passed && !$this->discarded;
    }

    public function isDiscarded(): bool
    {
        return $this->discarded;
    }

    /**
     * Whether the discard came from the environment rather than from the
     * input leaving the domain.
     */
    public function isSkipped(): bool
    {
        return $this->skipped;
    }
}
