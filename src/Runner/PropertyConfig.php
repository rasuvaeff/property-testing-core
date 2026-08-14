<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * Resolved knobs of one property run. The engine never reads the process
 * environment: an adapter resolves its attribute/env/defaults into this value
 * and hands it over, so a direct {@see PropertyRunner} call has no hidden
 * process state.
 *
 * @api
 */
final readonly class PropertyConfig
{
    /**
     * Which stages this run performs, in run order. Never empty.
     *
     * @var non-empty-list<Phase>
     */
    public array $phases;

    /**
     * The resolved shrink mode: what `$shrink`, `$shrinkBudgetMs` and
     * `$phases` amount to together, so the runner reads one field instead of
     * re-deriving a three-way interaction.
     */
    public ShrinkMode $shrink;

    /**
     * @param int $runs Number of successful checks to complete. Discarded runs do not count.
     * @param ?int $seed Seed of the random phase. Null lets the runner draw a random one.
     * @param ?int $maxShrinks Cap on accepted shrink steps. Null means no cap, 0 disables shrinking.
     * @param ?int $maxDiscards Maximum discarded runs before giving up. Null uses ten times $runs
     *        (saturating to PHP_INT_MAX).
     * @param ?int $timeoutMs Wall-clock deadline for a single run in milliseconds; null disables it.
     * @param ?int $budgetMs Wall-clock budget for the whole random phase in milliseconds; null disables it.
     * @param ?ShrinkMode $shrink How hard to minimise a counterexample. Null means
     *        {@see ShrinkMode::Full} unless $shrinkBudgetMs or $phases say otherwise.
     * @param ?int $shrinkBudgetMs Wall-clock budget for the shrink descent in milliseconds, which
     *        implies {@see ShrinkMode::Bounded}; null disables it. Unlike every other knob this one
     *        costs determinism: the same seed can minimise to different counterexamples on a fast
     *        and a slow machine, because how far the descent gets depends on how long the body
     *        takes. It answers "the descent hung", not "reproduce this exactly" — the corpus and an
     *        explicit seed remain the reproducible paths. A budget large enough to overflow its
     *        own nanosecond deadline is a configuration error.
     * @param ?list<Phase> $phases Stages to perform; null means {@see Phase::all()}. An empty list
     *        is a configuration error — a run with no phases has nothing to report — and so is a
     *        list holding anything other than a {@see Phase}: an unrecognised stage is not run,
     *        which would report green having checked nothing.
     * @param bool $derandomize Whether an unset $seed is derived from the property's id instead of
     *        drawn at random, so the same property on the same code always selects the same inputs.
     *        An explicit $seed always wins. The derivation lives in {@see PropertyRunner} because
     *        only it knows the id — and because a config that called `random_int()` would stop being
     *        a plain value.
     */
    public function __construct(
        public int $runs = 100,
        public ?int $seed = null,
        public ?int $maxShrinks = null,
        public ?int $maxDiscards = null,
        public ?int $timeoutMs = null,
        public ?int $budgetMs = null,
        ?ShrinkMode $shrink = null,
        public ?int $shrinkBudgetMs = null,
        ?array $phases = null,
        public bool $derandomize = false,
    ) {
        if ($runs < 1) {
            throw new \InvalidArgumentException('Runs must be greater than or equal to 1');
        }
        if ($maxShrinks !== null && $maxShrinks < 0) {
            throw new \InvalidArgumentException('Max shrinks must be greater than or equal to 0');
        }
        if ($maxDiscards !== null && $maxDiscards < 0) {
            throw new \InvalidArgumentException('Max discards must be greater than or equal to 0');
        }
        if ($timeoutMs !== null && $timeoutMs < 1) {
            throw new \InvalidArgumentException('Timeout must be greater than or equal to 1 millisecond');
        }
        if ($budgetMs !== null && $budgetMs < 1) {
            throw new \InvalidArgumentException('Budget must be greater than or equal to 1 millisecond');
        }
        if ($shrinkBudgetMs !== null && $shrinkBudgetMs < 1) {
            throw new \InvalidArgumentException('Shrink budget must be greater than or equal to 1 millisecond');
        }
        if ($shrinkBudgetMs !== null && $shrinkBudgetMs > $this->maxShrinkBudgetMs()) {
            throw new \InvalidArgumentException(
                'Shrink budget must be less than or equal to ' . $this->maxShrinkBudgetMs() . ' milliseconds',
            );
        }
        if ($shrink === ShrinkMode::Bounded && $shrinkBudgetMs === null) {
            throw new \InvalidArgumentException('Bounded shrinking requires a shrink budget');
        }
        if ($phases !== null) {
            if ($phases === []) {
                throw new \InvalidArgumentException('Phases must not be empty');
            }

            $this->assertPhases($phases);
        }

        $this->phases = $phases ?? Phase::all();
        $this->shrink = $this->resolveShrinkMode($shrink, $shrinkBudgetMs, $this->phases);
    }

    /**
     * True when this run performs $phase.
     *
     * @param Phase $phase The stage to test for.
     */
    public function runs(Phase $phase): bool
    {
        return in_array($phase, $this->phases, strict: true);
    }

    /**
     * The largest shrink budget that still behaves like one. The runner turns
     * it into a nanosecond deadline by adding it to a clock reading, and past
     * this point that arithmetic leaves the integer range: the product becomes
     * a float and the deadline stops being a deadline. Half the range is
     * reserved for the budget, which leaves the other half for the clock —
     * more than any monotonic source can ever report.
     */
    private function maxShrinkBudgetMs(): int
    {
        return intdiv(PHP_INT_MAX, 2_000_000);
    }

    /**
     * Rejects a phase set that static analysis would have caught. Untyped
     * input reaches this constructor from callers that read a configuration
     * file or a command line, and a stray value there is the worst kind of
     * silent failure: an unrecognised phase is simply not run, so the property
     * reports green having checked nothing.
     *
     * @param array<array-key, mixed> $phases
     */
    private function assertPhases(array $phases): void
    {
        if (!array_is_list($phases)) {
            throw new \InvalidArgumentException('Phases must be a list of Phase cases');
        }

        foreach ($phases as $phase) {
            if (!$phase instanceof Phase) {
                throw new \InvalidArgumentException('Phases must be a list of Phase cases');
            }
        }
    }

    /**
     * Folds the three inputs that can disable or bound shrinking into one
     * mode. The stricter side wins: a phase set without {@see Phase::Shrink}
     * or an explicit {@see ShrinkMode::Off} beats a budget, and a budget beats
     * the unbounded default.
     *
     * @param non-empty-list<Phase> $phases
     */
    private function resolveShrinkMode(?ShrinkMode $shrink, ?int $shrinkBudgetMs, array $phases): ShrinkMode
    {
        if ($shrink === ShrinkMode::Off || !in_array(Phase::Shrink, $phases, strict: true)) {
            return ShrinkMode::Off;
        }

        if ($shrinkBudgetMs !== null) {
            return ShrinkMode::Bounded;
        }

        return ShrinkMode::Full;
    }
}
