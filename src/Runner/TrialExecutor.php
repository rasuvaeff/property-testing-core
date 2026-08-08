<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * Executes the property body once with the given arguments.
 *
 * This is the single seam between the engine and a test framework: the runner
 * owns generation, shrinking, corpus replay and events, the executor owns the
 * one call into framework land. It is an interface rather than a callable
 * because each framework signals failure differently and the adapter must fold
 * that into a {@see TrialOutcome} (and may keep framework state of its own,
 * e.g. aggregated per-run result attributes).
 *
 * The contract deliberately does not require the executor to share memory with
 * the runner: a future subprocess executor (wall-clock watchdog for
 * non-terminating bodies) must fit behind the same interface.
 *
 * @api
 */
interface TrialExecutor
{
    /**
     * @param array<string, mixed> $arguments Keyed by parameter name, in
     *   {@see PropertyDefinition::$parameterNames} order.
     */
    public function execute(array $arguments): TrialOutcome;
}
