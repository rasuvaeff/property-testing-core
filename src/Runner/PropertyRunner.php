<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\CoverageViolationException;
use Rasuvaeff\PropertyTesting\DeadlineExceededException;
use Rasuvaeff\PropertyTesting\Event\CorpusPruned;
use Rasuvaeff\PropertyTesting\Event\CorpusReplayed;
use Rasuvaeff\PropertyTesting\Event\CorpusStored;
use Rasuvaeff\PropertyTesting\Event\ExampleFinished;
use Rasuvaeff\PropertyTesting\Event\ExampleStarted;
use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\Event\PropertyFinished;
use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunDiscarded;
use Rasuvaeff\PropertyTesting\Event\RunFailed;
use Rasuvaeff\PropertyTesting\Event\RunPassed;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\Event\ShrinkTried;
use Rasuvaeff\PropertyTesting\ExampleViolationException;
use Rasuvaeff\PropertyTesting\GaveUpException;
use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Rasuvaeff\PropertyTesting\Internal\DrawContext;
use Rasuvaeff\PropertyTesting\Internal\ShrinkPath;
use Rasuvaeff\PropertyTesting\PathViolationException;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\RegressionViolationException;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\TimeBudgetExceededException;

/**
 * The framework-agnostic property engine: runs the explicit examples, replays
 * the regression corpus, generates random inputs until the required number of
 * successful checks completes, and shrinks the first falsifying run to a
 * minimal counterexample.
 *
 * The runner knows nothing about test frameworks: the body executes behind a
 * {@see TrialExecutor}, the outcome is a structured {@see PropertyResult}
 * carrying the engine's own exception types, observers listen through
 * {@see PropertyListener} events, and the regression corpus is an explicit
 * {@see Corpus} the caller resolves (no environment reads, no output, no
 * exit). Sequential-only: {@see Classify} and {@see DrawContext} are
 * process-local statics, armed and drained around every body execution.
 *
 * @api
 */
final readonly class PropertyRunner
{
    /**
     * Cap on accepted shrink steps once in-body draws are on the tape. An
     * accepted candidate can change the body's control flow and regrow the
     * tape with fresh trees, so tree depth alone no longer bounds the descent;
     * the cap guarantees termination. An explicit
     * {@see PropertyConfig::$maxShrinks} still wins.
     */
    private const int MAX_DRAW_SHRINK_STEPS = 1000;

    private Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new MonotonicClock();
    }

    /**
     * @param iterable<PropertyListener> $listeners Observers of the run's
     *   lifecycle events, notified in the given order.
     */
    public function run(
        PropertyDefinition $property,
        TrialExecutor $executor,
        iterable $listeners = [],
        ?Corpus $corpus = null,
    ): PropertyResult {
        $listeners = array_values(is_array($listeners) ? $listeners : iterator_to_array($listeners));

        $config = $property->config;
        $runs = $config->runs;
        $maxDiscards = $config->maxDiscards
            ?? ($runs > intdiv(PHP_INT_MAX, 10) ? PHP_INT_MAX : $runs * 10);
        $seed = $config->seed ?? ($config->derandomize
            ? self::derivedSeed($property->id)
            : random_int(0, PHP_INT_MAX));

        // Discard requirements and a draw tape a previously aborted property may
        // have left over.
        Classify::flushRequirements();
        DrawContext::disarm();

        $this->emit($listeners, new PropertyStarted($property->id, $seed, $runs));

        if ($config->runs(Phase::Examples)) {
            $exampleFailure = $this->runExamples($property, $executor, $seed, $listeners);

            if ($exampleFailure instanceof PropertyResult) {
                return $this->finish($listeners, $property->id, $exampleFailure, coverageAssessed: false);
            }
        }

        // Opt-in regression replay: re-run every recorded past failure first
        // (unless the adapter pinned the seed). A replay that fails again — in
        // any way — is reported immediately; only an entry whose replay passes
        // cleanly is pruned. An inconclusive replay must not delete the
        // recorded regression.
        if ($corpus instanceof Corpus && $property->replayRegressions && $config->runs(Phase::Corpus)) {
            foreach ($corpus->recall($property->id, $property->parameterNames) as $entry) {
                if ($entry->isValues()) {
                    $this->emit($listeners, new CorpusReplayed($property->id, isValues: true, arguments: $entry->arguments, seed: $entry->seed));
                    $replay = $this->replayRegression($property, $executor, $entry->arguments, $entry->seed);

                    if ($replay instanceof PropertyResult) {
                        return $this->finish($listeners, $property->id, $replay, coverageAssessed: false);
                    }
                } else {
                    $this->emit($listeners, new CorpusReplayed($property->id, isValues: false, arguments: [], seed: $entry->seed));
                    // Without the pinned path: it records ONE descent — the
                    // one the random phase below produces — and a recorded
                    // regression is a different run. Applied here it would
                    // report "path no longer applies" over the regression the
                    // corpus exists to surface.
                    $replay = $this->runPhase($property, $executor, new Random($entry->seed), $entry->seed, $runs, $maxDiscards, $listeners, null);

                    if ($replay->failure() instanceof \Throwable) {
                        return $this->finish($listeners, $property->id, $replay, self::assessedCoverage($replay));
                    }
                }

                $corpus->prune($property->id, $entry);
                $this->emit($listeners, new CorpusPruned($property->id, $entry->isValues(), $entry->seed));
            }
        }

        // Without the random phase there is nothing left to check: the examples
        // and the corpus have already passed. The statistics say so honestly —
        // zero attempts, zero checks — instead of reporting the configured run
        // count as if it had happened, and coverage requirements are dropped
        // rather than assessed against an empty denominator.
        if (!$config->runs(Phase::Random)) {
            Classify::flushRequirements();

            // coverageAssessed: false — the requirements were dropped a few
            // lines up, not judged, and the report must not claim otherwise.
            return $this->finish($listeners, $property->id, new Passed(new RunStatistics(
                attempts: 0,
                discards: 0,
                checks: 0,
                classifications: [],
            )), coverageAssessed: false);
        }

        $result = $this->runPhase($property, $executor, new Random($seed), $seed, $runs, $maxDiscards, $listeners, $config->path);

        if ($corpus instanceof Corpus && $result instanceof Falsified) {
            $corpus->remember($property->id, $result->counterExample(), $property->parameterNames);
            $this->emit($listeners, new CorpusStored($property->id, $result->counterExample()));
        }

        return $this->finish($listeners, $property->id, $result, self::assessedCoverage($result));
    }

    /**
     * The seed a derandomised run uses when none was pinned: a pure function of
     * the property's id, so the same property selects the same inputs on every
     * machine and every supported PHP version.
     *
     * Fifteen hex digits of a SHA-256 digest are 60 bits, which always fits an
     * int — and the mapping from a seed to the values it generates is untouched,
     * so this changes which seed a run picks, never what that seed produces.
     */
    private static function derivedSeed(string $propertyId): int
    {
        return (int) hexdec(substr(hash('sha256', $propertyId), 0, 15));
    }

    /**
     * Notifies every listener, in registration order. A listener exception is
     * deliberately not caught: a listener observes the run, and its failure is
     * an infrastructure failure of the run itself, not something to hide.
     *
     * @param list<PropertyListener> $listeners
     */
    private function emit(array $listeners, PropertyEvent $event): void
    {
        foreach ($listeners as $listener) {
            $listener->onEvent($event);
        }
    }

    /**
     * Emits {@see PropertyFinished} and passes the result through — the single
     * exit point for every outcome that got past configuration.
     *
     * @param list<PropertyListener> $listeners
     */
    private function finish(
        array $listeners,
        string $propertyId,
        PropertyResult $result,
        bool $coverageAssessed,
    ): PropertyResult {
        $this->emit($listeners, new PropertyFinished($propertyId, $result->failure(), $this->distribution($result, $coverageAssessed)));

        return $result;
    }

    /**
     * Whether an outcome came out of a completed check loop, which is the only
     * place the {@see Classify::cover()} requirements are judged. Every other
     * exit ends before the assessment — including the run that performs no
     * random phase at all.
     */
    private static function assessedCoverage(PropertyResult $result): bool
    {
        return $result instanceof Passed || $result instanceof CoverageFailed;
    }

    /**
     * The distribution of the run that just ended, for the outcomes that carry
     * counters; null for the rest. Built here, once, from counters the phase
     * accumulated — {@see Classify::label()} runs inside the body on every run,
     * this runs after the last one.
     */
    private function distribution(PropertyResult $result, bool $coverageAssessed): ?DistributionReport
    {
        $statistics = match (true) {
            $result instanceof Passed => $result->statistics,
            $result instanceof CoverageFailed => $result->statistics,
            $result instanceof GaveUp => $result->statistics,
            $result instanceof TimeBudgetExceeded => $result->statistics,
            default => null,
        };

        if (!$statistics instanceof RunStatistics) {
            return null;
        }

        return DistributionReport::of($statistics, $coverageAssessed);
    }

    /**
     * The random-input phase: generate until the required number of successful
     * checks has completed, shrink the first falsifying run into a
     * {@see Falsified} result, otherwise assess the {@see Classify::cover()}
     * requirements. Runs once per seed, so the regression-replay phase can
     * re-run it with a recorded seed.
     *
     * @param list<PropertyListener> $listeners
     * @param ?string $path The descent to follow instead of searching for one; null searches.
     *        Passed in rather than read off the config because a corpus seed replay is a
     *        different run from the one the path was recorded on.
     */
    private function runPhase(
        PropertyDefinition $property,
        TrialExecutor $executor,
        Random $random,
        int $seed,
        int $runs,
        int $maxDiscards,
        array $listeners,
        ?string $path,
    ): PropertyResult {
        $maxShrinks = $property->config->maxShrinks;
        $shrinkMode = $property->config->shrink;
        $shrinkBudgetMs = $shrinkMode === ShrinkMode::Bounded ? $property->config->shrinkBudgetMs : null;
        $timeoutMs = $property->config->timeoutMs;
        $budgetMs = $property->config->budgetMs;

        $skips = 0;
        $checks = 0;
        $attempts = 0;
        $phaseStart = $this->clock->nanoseconds();
        /** @var array<array-key, int> $classifications */
        $classifications = [];

        while ($checks < $runs) {
            // The budget is a wall-clock cap on the whole phase: once it runs
            // out, completing the remaining checks would only overrun further,
            // so the property fails instead of silently checking less.
            if ($budgetMs !== null) {
                $phaseElapsedNs = $this->clock->nanoseconds() - $phaseStart;

                if ($phaseElapsedNs > $budgetMs * 1_000_000) {
                    // Drained AND kept: an exit that never reached the coverage
                    // assessment still reports what the body demanded, beside
                    // the shares it actually got.
                    $requirements = Classify::flushRequirements();

                    return new TimeBudgetExceeded(
                        exception: new TimeBudgetExceededException(
                            propertyName: $property->name,
                            budgetMs: $budgetMs,
                            elapsedMs: (float) $phaseElapsedNs / 1e6,
                            successfulRuns: $checks,
                            requiredRuns: $runs,
                        ),
                        statistics: new RunStatistics($attempts, $skips, $checks, $classifications, $requirements),
                    );
                }
            }

            ++$attempts;
            Classify::beginRun();

            try {
                $trees = $this->generate($property->generators, $property->parameterNames, $random);
            } catch (GenerationExhausted $exhausted) {
                // A generator could not produce a valid value (e.g. Gen::filter()
                // whose predicate rejected every draw). Report it as a clean
                // failure rather than let it crash the run as an uncaught error.
                Classify::flushRun();
                Classify::flushRequirements();

                return new GenerationFailed($exhausted);
            }

            $arguments = $this->values($trees);

            $this->emit($listeners, new RunStarted($property->id, $attempts, $arguments));

            DrawContext::arm($random);
            $runStart = $this->clock->nanoseconds();
            $outcome = $executor->execute($arguments);
            $runElapsedNs = $this->clock->nanoseconds() - $runStart;
            $draws = DrawContext::disarm();
            $labels = Classify::flushRun();

            // A discarded run is neither a failure nor a check.
            if ($outcome->isDiscarded()) {
                $this->emit($listeners, new RunDiscarded($property->id, $attempts, $arguments, $this->drawArguments($draws)));
                ++$skips;

                if ($skips > $maxDiscards) {
                    $requirements = Classify::flushRequirements();

                    return new GaveUp(
                        exception: new GaveUpException(
                            propertyName: $property->name,
                            requiredRuns: $runs,
                            successfulRuns: $checks,
                            discardedRuns: $skips,
                            attempts: $attempts,
                            maxDiscards: $maxDiscards,
                        ),
                        statistics: new RunStatistics($attempts, $skips, $checks, $classifications, $requirements),
                    );
                }

                continue;
            }

            if ($outcome->isFailed()) {
                $this->emit($listeners, new RunFailed($property->id, $attempts, $arguments, $this->drawArguments($draws), $outcome->failure, $runElapsedNs));
                $descent = $path === null
                    ? $this->shrink($property->id, $executor, $trees, $draws, $random, $maxShrinks, $shrinkMode, $shrinkBudgetMs, $listeners)
                    : $this->replayPath($property->id, $executor, $trees, $draws, $random, $path, $listeners);

                // Drain the coverage requirements like every other exit path:
                // the 2.8 interceptor left them armed here and relied on the
                // next property's defensive flush, which a standalone runner
                // caller does not get between run() calls.
                Classify::flushRequirements();

                if ($descent instanceof PathViolationException) {
                    return new PathFailed($descent);
                }

                [$shrunk, $shrunkDraws, $shrinkSteps, $shrunkFailure, $shrinkTrials, $shrinkPath] = $descent;

                return new Falsified(new PropertyViolationException(new CounterExample(
                    seed: $seed,
                    runsBeforeFailure: $checks,
                    originalArguments: array_merge($arguments, $this->drawArguments($draws)),
                    shrunkArguments: array_merge($shrunk, $shrunkDraws),
                    shrinkSteps: $shrinkSteps,
                    // Report the failure of the minimised sequence, not the
                    // original: for a shrunk counterexample the two can differ
                    // (e.g. a different failing step), and the developer acts on
                    // the minimal one. Falls back to the original when nothing shrank.
                    failure: $shrunkFailure ?? $outcome->failure,
                    skips: $skips,
                    shrinkTrials: $shrinkTrials,
                    path: $shrinkPath,
                )));
            }

            // A passing but overlong run is a failure in its own right: the
            // input is pathological for the code under test. Checked after the
            // falsification branch so an assertion failure (more actionable)
            // wins when both happen, but before RunPassed — a timed-out run
            // must not be announced as successful. Reported unshrunk — shrink
            // acceptance would have to re-measure wall time, and timing noise
            // makes that descent non-deterministic.
            if ($timeoutMs !== null && $runElapsedNs > $timeoutMs * 1_000_000) {
                Classify::flushRequirements();

                return new DeadlineExceeded(new DeadlineExceededException(
                    propertyName: $property->name,
                    arguments: array_merge($arguments, $this->drawArguments($draws)),
                    elapsedMs: (float) $runElapsedNs / 1e6,
                    timeoutMs: $timeoutMs,
                ));
            }

            $this->emit($listeners, new RunPassed($property->id, $attempts, $arguments, $this->drawArguments($draws), $labels, $runElapsedNs));

            foreach ($labels as $label) {
                $classifications[$label] = ($classifications[$label] ?? 0) + 1;
            }

            ++$checks;
        }

        $requirements = Classify::flushRequirements();

        $statistics = new RunStatistics($attempts, $skips, $checks, $classifications, $requirements);
        $violation = $this->coverageViolation($property->name, $requirements, $classifications, $checks);

        if ($violation instanceof CoverageViolationException) {
            return new CoverageFailed($violation, $statistics);
        }

        return new Passed($statistics);
    }

    /**
     * Check the {@see Classify::cover()} requirements against the label counts
     * of the passing runs; every run passed, but an under-covered label means
     * the pass is (partially) vacuous and must fail. The successful-run loop
     * guarantees the denominator is always positive.
     *
     * @param array<array-key, float> $requirements Keyed by label — `array-key`, not `string`,
     *        because PHP stores a numeric label under an integer key (see {@see Classify::$current}).
     * @param array<array-key, int> $classifications Keyed by label, for the same reason.
     */
    private function coverageViolation(
        string $name,
        array $requirements,
        array $classifications,
        int $checks,
    ): ?CoverageViolationException {
        if ($requirements === []) {
            return null;
        }

        $unmet = [];
        foreach ($requirements as $label => $minPercent) {
            $count = $classifications[$label] ?? 0;
            $percent = ((float) $count / (float) $checks) * 100.0;

            if ($percent < $minPercent) {
                $unmet[] = sprintf(
                    '"%s" %.1f%% < required %.1f%% (%d/%d)',
                    $label,
                    $percent,
                    $minPercent,
                    $count,
                    $checks,
                );
            }
        }

        if ($unmet === []) {
            return null;
        }

        return new CoverageViolationException(sprintf(
            'Property "%s" coverage not met: %s',
            $name,
            implode('; ', $unmet),
        ));
    }

    /**
     * @param array<string, ArbitraryInterface> $generators
     * @param list<string> $parameterNames
     * @return array<string, Shrinkable>
     */
    private function generate(array $generators, array $parameterNames, Random $random): array
    {
        return array_combine(
            $parameterNames,
            array_map(
                static fn(string $name): Shrinkable => $generators[$name]->generate($random),
                $parameterNames,
            ),
        );
    }

    /**
     * @param array<string, Shrinkable> $trees
     * @return array<string, mixed>
     */
    private function values(array $trees): array
    {
        return array_map(
            static fn(Shrinkable $tree): mixed => $tree->value,
            $trees,
        );
    }

    /**
     * Runs the fixed examples (if any) before the random inputs, short-circuiting
     * on the first failure — a pinned example is already minimal, so it is not
     * shrunk. Returns the failing result, or null when all pass.
     *
     * @param list<PropertyListener> $listeners
     */
    private function runExamples(
        PropertyDefinition $property,
        TrialExecutor $executor,
        int $seed,
        array $listeners,
    ): ?PropertyResult {
        $timeoutMs = $property->config->timeoutMs;
        $index = 0;
        // Examples may call Gen::draw(); their draws come from a dedicated
        // deterministic stream so the random phase's sequence is untouched.
        $random = new Random($seed);

        foreach ($property->examples as $arguments) {
            $this->emit($listeners, new ExampleStarted($property->id, $index, $arguments));
            Classify::beginRun();
            DrawContext::arm($random);
            $runStart = $this->clock->nanoseconds();
            $outcome = $executor->execute($this->named($property->parameterNames, $arguments));
            $runElapsedNs = $this->clock->nanoseconds() - $runStart;
            DrawContext::disarm();
            Classify::flushRun();

            $exampleFailed = $outcome->isFailed();

            // The per-run deadline applies to examples too: a pinned input can
            // be the pathological one. Example arity matches the parameter
            // list, so positional arguments are reported under their names.
            // Evaluated before ExampleFinished so a timed-out example is not
            // announced to listeners as successful; the assertion failure
            // still wins when both happen.
            $deadlineFailure = !$exampleFailed && $timeoutMs !== null && $runElapsedNs > $timeoutMs * 1_000_000
                ? new DeadlineExceededException(
                    propertyName: $property->name,
                    arguments: $this->named($property->parameterNames, $arguments),
                    elapsedMs: (float) $runElapsedNs / 1e6,
                    timeoutMs: $timeoutMs,
                )
                : null;

            $this->emit($listeners, new ExampleFinished($property->id, $index, $arguments, $exampleFailed ? $outcome->failure : $deadlineFailure));

            if ($exampleFailed) {
                return new ExampleFailed(new ExampleViolationException($index, $arguments, $outcome->failure));
            }

            if ($deadlineFailure instanceof DeadlineExceededException) {
                return new DeadlineExceeded($deadlineFailure);
            }

            ++$index;
        }

        return null;
    }

    /**
     * Replays one recorded regression: the minimised input of an earlier failure,
     * run once with the very values that failed. Returns the failing result, or
     * null when the input no longer falsifies the property (the caller then prunes
     * the entry) — including when the run is discarded, which means the recorded
     * input has fallen out of the property's domain.
     *
     * Mirrors {@see runExamples()}'s lifecycle discipline: the input is not
     * shrunk (it is already minimal) and the per-run deadline applies.
     *
     * @param array<string, mixed> $arguments The recorded input, keyed by parameter name.
     * @param int $seed Seed of the run that recorded it.
     */
    private function replayRegression(
        PropertyDefinition $property,
        TrialExecutor $executor,
        array $arguments,
        int $seed,
    ): ?PropertyResult {
        $timeoutMs = $property->config->timeoutMs;

        // Recall validated the key set against the live signature; order the
        // values the way the property declares its parameters.
        $ordered = array_combine(
            $property->parameterNames,
            array_map(static fn(string $name): mixed => $arguments[$name], $property->parameterNames),
        );

        Classify::beginRun();
        // A recorded regression may call Gen::draw(); its draws come from a
        // dedicated deterministic stream keyed on the recording run's seed.
        DrawContext::arm(new Random($seed));
        $runStart = $this->clock->nanoseconds();
        $outcome = $executor->execute($ordered);
        $runElapsedNs = $this->clock->nanoseconds() - $runStart;
        DrawContext::disarm();
        Classify::flushRun();

        if ($outcome->isFailed()) {
            return new RegressionFailed(new RegressionViolationException($arguments, $seed, $outcome->failure));
        }

        if ($timeoutMs !== null && $runElapsedNs > $timeoutMs * 1_000_000) {
            return new DeadlineExceeded(new DeadlineExceededException(
                propertyName: $property->name,
                arguments: $arguments,
                elapsedMs: (float) $runElapsedNs / 1e6,
                timeoutMs: $timeoutMs,
            ));
        }

        return null;
    }

    /**
     * Greedy per-parameter descent through each parameter's shrink tree: try the
     * candidates of one parameter's current node, accept the first that still
     * fails (descending into that candidate's subtree), and keep iterating until
     * a full pass produces no improvement. Termination is guaranteed by the
     * {@see ArbitraryInterface} contract: every branch of a shrink tree is finite.
     *
     * In-body draws shrink through a replay tape walked like extra parameters:
     * each trial re-runs the body with the modified tape, and the draws that
     * trial actually used (the replayed prefix plus a freshly generated suffix,
     * when control flow changed) become the tape of the next round on
     * acceptance. Because an accepted candidate can regrow the tape with fresh
     * trees, the finite-tree argument no longer bounds the descent — with a
     * non-empty tape the accepted steps are additionally capped by
     * {@see self::MAX_DRAW_SHRINK_STEPS}.
     *
     * {@see ShrinkMode::Off} skips the descent entirely — no trial, no shrink
     * event, the counterexample exactly as generated. {@see ShrinkMode::Bounded}
     * stops on wall clock instead of on accepted steps, checked at the same
     * points as the step cap (before each parameter's and each tape position's
     * candidate search), and returns the best candidate reached so far.
     *
     * @param array<string, Shrinkable> $trees The failing arguments' shrink trees.
     * @param list<Shrinkable> $tape The failing run's recorded in-body draws.
     * @param ?int $maxShrinks Cap on accepted shrink steps; null means no cap, 0 disables shrinking.
     * @param ?int $budgetMs Wall-clock budget of the whole descent; non-null exactly when $mode is
     *        {@see ShrinkMode::Bounded}.
     * @param list<PropertyListener> $listeners
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: ?\Throwable, 4: int, 5: string} The
     *         minimised arguments, the minimised draws (as `draw#N` pseudo-arguments), the number
     *         of accepted shrink steps, the failure of the last accepted candidate (null when
     *         nothing shrank), the total number of candidates tried (accepted and rejected), and
     *         the descent itself as a replayable path (see {@see ShrinkPath}). Every exit carries
     *         the steps actually taken — a descent cut short by the cap or the budget is still
     *         replayable up to where it stopped.
     */
    private function shrink(
        string $propertyId,
        TrialExecutor $executor,
        array $trees,
        array $tape,
        Random $random,
        ?int $maxShrinks,
        ShrinkMode $mode,
        ?int $budgetMs,
        array $listeners,
    ): array {
        if ($mode === ShrinkMode::Off) {
            return [$this->values($trees), $this->drawArguments($tape), 0, null, 0, ''];
        }

        $deadlineNs = $budgetMs === null ? null : $this->clock->nanoseconds() + $budgetMs * 1_000_000;
        $current = $trees;
        $currentTape = $tape;
        $steps = 0;
        $trials = 0;
        $acceptedFailure = null;
        /** @var list<array{name: string, index: int<0, max>}> $acceptedSteps */
        $acceptedSteps = [];

        do {
            $improved = false;

            foreach (array_keys($current) as $name) {
                // Stop before accepting any further candidate once the cap is hit.
                // Checking here (before the per-parameter search) makes maxShrinks=0
                // return the original counterexample with zero accepted steps.
                if ($this->capReached($maxShrinks, $currentTape, $steps) || $this->budgetSpent($deadlineNs)) {
                    return [$this->values($current), $this->drawArguments($currentTape), $steps, $acceptedFailure, $trials, ShrinkPath::format($acceptedSteps)];
                }

                // Counted over every candidate the enumeration yields, skipped
                // ones included: the index is what a replay indexes back into,
                // so both sides must agree on what they are counting.
                $index = -1;

                foreach ($current[$name]->shrinks() as $candidate) {
                    ++$index;

                    // A candidate whose value equals the current one (possible under a
                    // non-injective map) makes no progress; skip it and its subtree.
                    if ($candidate->value === $current[$name]->value) {
                        continue;
                    }

                    // Replace one parameter in place: array_replace keeps $current's
                    // key order, so the executor still receives each value under the
                    // right parameter. Array union ([$name => ...] + $current) would
                    // move $name to the front and scramble non-leading parameters.
                    $trial = array_replace($current, [$name => $candidate]);
                    [$outcome, $recorded] = $this->trial($executor, $trial, $currentTape, $random);
                    ++$trials;

                    $accepted = $outcome->isFailed();
                    $this->emit($listeners, new ShrinkTried($propertyId, $name, $candidate->value, $accepted));

                    if ($accepted) {
                        $this->emit($listeners, new ShrinkAccepted($propertyId, $steps + 1, $name, $current[$name]->value, $candidate->value));

                        $current = $trial;
                        $currentTape = $recorded;
                        $acceptedFailure = $outcome->failure;
                        $acceptedSteps[] = ['name' => $name, 'index' => $index];
                        ++$steps;
                        $improved = true;

                        break;
                    }
                }
            }

            // Walk the tape positions like extra parameters. The bound is re-read
            // every iteration (a while, NOT a hoisted-count for): an accepted
            // candidate truncates the tape when the body draws fewer values under
            // the smaller prefix.
            $position = 0;

            while ($position < count($currentTape)) {
                if ($this->capReached($maxShrinks, $currentTape, $steps) || $this->budgetSpent($deadlineNs)) {
                    return [$this->values($current), $this->drawArguments($currentTape), $steps, $acceptedFailure, $trials, ShrinkPath::format($acceptedSteps)];
                }

                $index = -1;

                foreach ($currentTape[$position]->shrinks() as $candidate) {
                    ++$index;

                    if ($candidate->value === $currentTape[$position]->value) {
                        continue;
                    }

                    $trialTape = array_replace($currentTape, [$position => $candidate]);
                    [$outcome, $recorded] = $this->trial($executor, $current, $trialTape, $random);
                    ++$trials;

                    $accepted = $outcome->isFailed();
                    $this->emit($listeners, new ShrinkTried($propertyId, 'draw#' . ($position + 1), $candidate->value, $accepted));

                    if ($accepted) {
                        $this->emit($listeners, new ShrinkAccepted($propertyId, $steps + 1, 'draw#' . ($position + 1), $currentTape[$position]->value, $candidate->value));

                        $currentTape = $recorded;
                        $acceptedFailure = $outcome->failure;
                        $acceptedSteps[] = ['name' => 'draw#' . ($position + 1), 'index' => $index];
                        ++$steps;
                        $improved = true;

                        break;
                    }
                }

                ++$position;
            }
        } while ($improved);

        return [$this->values($current), $this->drawArguments($currentTape), $steps, $acceptedFailure, $trials, ShrinkPath::format($acceptedSteps)];
    }

    /**
     * Follows a recorded descent instead of searching for one: each step names
     * a node and the candidate of it that was accepted, so the body runs once
     * per step rather than once per candidate a search would have to try.
     *
     * Every step is executed rather than merely applied, and that is the point
     * of the design: a path indexes into shrink candidates, so a generator that
     * changed turns a stale step into a *different* input that may still fail.
     * Re-running each step converts that from a silently wrong counterexample
     * into a {@see PathViolationException} naming the step that broke.
     *
     * What it does not save is the random phase — reaching the failing run
     * means executing the runs before it, because bodies consume randomness
     * through {@see \Rasuvaeff\PropertyTesting\Gen::draw()} and discards depend
     * on the body. The saving is the descent.
     *
     * {@see self::MAX_DRAW_SHRINK_STEPS} deliberately does not apply here. That
     * cap exists because an accepted candidate can regrow the tape with fresh
     * trees, so a search has no finite bound of its own; a path is finite by
     * construction, and truncating a replay at the cap would return a
     * counterexample the path does not describe — the silent wrong answer this
     * whole design is built to avoid.
     *
     * @param array<string, Shrinkable> $trees The failing arguments' shrink trees.
     * @param list<Shrinkable> $tape The failing run's recorded in-body draws.
     * @param list<PropertyListener> $listeners
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: ?\Throwable, 4: int, 5: string}|PathViolationException
     *         The same tuple {@see shrink()} returns, or the reason the path could not be followed.
     */
    private function replayPath(
        string $propertyId,
        TrialExecutor $executor,
        array $trees,
        array $tape,
        Random $random,
        string $path,
        array $listeners,
    ): array|PathViolationException {
        $current = $trees;
        $currentTape = $tape;
        $steps = 0;
        $acceptedFailure = null;

        foreach (ShrinkPath::parse($path) as $step) {
            $name = $step['name'];
            $number = ShrinkPath::drawNumber($name);
            $position = $number === null ? null : $number - 1;
            $node = $position === null ? ($current[$name] ?? null) : ($currentTape[$position] ?? null);

            if (!$node instanceof Shrinkable) {
                return $this->pathBroken($path, $steps, $step, 'names something this run does not have');
            }

            $candidate = $this->candidateAt($node, $step['index']);

            if (!$candidate instanceof Shrinkable) {
                return $this->pathBroken($path, $steps, $step, 'has no such candidate any more');
            }

            // A candidate that no longer differs from the value it replaces
            // would re-run the identical input, still fail, and report a path
            // that "applied" without minimising anything.
            if ($candidate->value === $node->value) {
                return $this->pathBroken($path, $steps, $step, 'no longer changes the value');
            }

            $trial = $position === null ? array_replace($current, [$name => $candidate]) : $current;
            $trialTape = $position === null ? $currentTape : array_replace($currentTape, [$position => $candidate]);
            [$outcome, $recorded] = $this->trial($executor, $trial, $trialTape, $random);

            $accepted = $outcome->isFailed();
            $this->emit($listeners, new ShrinkTried($propertyId, $name, $candidate->value, $accepted));

            if (!$accepted) {
                return $this->pathBroken($path, $steps, $step, 'no longer falsifies the property');
            }

            $this->emit($listeners, new ShrinkAccepted($propertyId, $steps + 1, $name, $node->value, $candidate->value));

            $current = $trial;
            $currentTape = $recorded;
            $acceptedFailure = $outcome->failure;
            ++$steps;
        }

        return [$this->values($current), $this->drawArguments($currentTape), $steps, $acceptedFailure, $steps, $path];
    }

    /**
     * @param array{name: string, index: int<0, max>} $step
     * @param int $followed Steps followed before this one, so the reported position is one-based.
     */
    private function pathBroken(string $path, int $followed, array $step, string $reason): PathViolationException
    {
        return new PathViolationException(
            path: $path,
            step: $followed + 1,
            segment: ShrinkPath::format([$step]),
            reason: $reason,
        );
    }

    /**
     * The candidate at a recorded index, counted over every candidate the
     * enumeration yields — the same counting {@see shrink()} records — or null
     * when the enumeration is now shorter than the path expects.
     *
     * @param Shrinkable<mixed> $node
     * @return ?Shrinkable<mixed>
     */
    private function candidateAt(Shrinkable $node, int $index): ?Shrinkable
    {
        $position = 0;

        foreach ($node->shrinks() as $candidate) {
            if ($position === $index) {
                return $candidate;
            }

            ++$position;
        }

        return null;
    }

    /**
     * One shrink trial: run the body with the candidate arguments while the
     * draw context replays $tape, and report the outcome together with the
     * draws the body actually used. Shrink trials are not timed — the per-run
     * deadline judges generated inputs, not the descent.
     *
     * @param array<string, Shrinkable> $trees
     * @param list<Shrinkable> $tape
     * @return array{0: TrialOutcome, 1: list<Shrinkable>}
     */
    private function trial(TrialExecutor $executor, array $trees, array $tape, Random $random): array
    {
        DrawContext::arm($random, $tape);
        $outcome = $executor->execute($this->values($trees));

        return [$outcome, DrawContext::disarm()];
    }

    /**
     * Whether shrinking must stop before accepting another candidate. The cap
     * is re-derived from the CURRENT tape: a body that drew nothing on the
     * original run can still start drawing once a shrunk parameter changes its
     * control flow, and from that point the draw cap must engage.
     *
     * @param list<Shrinkable> $tape
     */
    private function capReached(?int $maxShrinks, array $tape, int $steps): bool
    {
        $cap = $maxShrinks ?? ($tape === [] ? null : self::MAX_DRAW_SHRINK_STEPS);

        return $cap !== null && $steps >= $cap;
    }

    /**
     * True once a bounded descent has run out of wall clock. A null deadline
     * (every mode but {@see ShrinkMode::Bounded}) never expires.
     */
    private function budgetSpent(?int $deadlineNs): bool
    {
        return $deadlineNs !== null && $this->clock->nanoseconds() >= $deadlineNs;
    }

    /**
     * Render in-body draws as pseudo-arguments (`draw#1`, `draw#2`, ...) for
     * counterexample reporting. `#` cannot occur in a PHP parameter name, so
     * the keys never collide with real parameters.
     *
     * @param list<Shrinkable> $draws
     * @return array<string, mixed>
     */
    private function drawArguments(array $draws): array
    {
        return array_combine(
            array_map(static fn(int $index): string => 'draw#' . ($index + 1), array_keys($draws)),
            array_map(static fn(Shrinkable $draw): mixed => $draw->value, $draws),
        );
    }

    /**
     * @param list<string> $parameterNames
     * @param list<mixed> $arguments
     * @return array<string, mixed>
     */
    private function named(array $parameterNames, array $arguments): array
    {
        return array_combine($parameterNames, $arguments);
    }
}
