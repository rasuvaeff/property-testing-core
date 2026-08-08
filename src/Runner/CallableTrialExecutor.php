<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\AssumptionSkipped;

/**
 * The standalone executor: a plain callback is the property body. A normal
 * return passes, {@see \Rasuvaeff\PropertyTesting\Assume::that()} discards,
 * any other throwable fails the trial.
 *
 * This is what a custom harness (CLI script, application-side checker) uses
 * with {@see PropertyRunner} directly, without any test framework.
 *
 * @api
 */
final readonly class CallableTrialExecutor implements TrialExecutor
{
    /**
     * @param \Closure(mixed...): void $body Receives the generated arguments positionally,
     *   in {@see PropertyDefinition::$parameterNames} order.
     */
    public function __construct(
        private \Closure $body,
    ) {}

    #[\Override]
    public function execute(array $arguments): TrialOutcome
    {
        try {
            ($this->body)(...array_values($arguments));
        } catch (AssumptionSkipped) {
            return TrialOutcome::discarded();
        } catch (\Throwable $failure) {
            return TrialOutcome::failed($failure);
        }

        return TrialOutcome::passed();
    }
}
