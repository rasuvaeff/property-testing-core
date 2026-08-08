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
    private function __construct(
        private bool $passed,
        private bool $discarded,
        public ?\Throwable $failure,
    ) {}

    public static function passed(): self
    {
        return new self(passed: true, discarded: false, failure: null);
    }

    /**
     * @param ?\Throwable $failure The assertion or exception the body raised;
     *   null when the framework reports failure without a throwable.
     */
    public static function failed(?\Throwable $failure = null): self
    {
        return new self(passed: false, discarded: false, failure: $failure);
    }

    /**
     * The run was discarded via `Assume::that()` — neither a failure nor a
     * successful check.
     */
    public static function discarded(): self
    {
        return new self(passed: false, discarded: true, failure: null);
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
}
