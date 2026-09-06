<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * A random-phase attempt was discarded — neither a failure nor a successful
 * check. Two things arrive here: an input the property rejected through
 * {@see \Rasuvaeff\PropertyTesting\Assume::that()}, and a run the environment
 * refused (a skipped body or lifecycle hook). `$skipped` says which, because
 * the two spend separate budgets and mean opposite things — one is a statement
 * about the generators, the other about the machine.
 *
 * @api
 */
final readonly class RunDiscarded implements PropertyEvent
{
    /**
     * @param array<string, mixed> $arguments Generated inputs, keyed by parameter name.
     * @param array<string, mixed> $draws In-body draws as `draw#N` pseudo-arguments.
     * @param bool $skipped The environment refused this run, rather than the property
     *        discarding the input it was given.
     */
    public function __construct(
        public string $propertyId,
        public int $attempt,
        public array $arguments,
        public array $draws,
        public bool $skipped = false,
    ) {}
}
