<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * A random-phase attempt falsified the property; shrinking follows.
 *
 * @api
 */
final readonly class RunFailed implements PropertyEvent
{
    /**
     * @param array<string, mixed> $arguments Generated inputs, keyed by parameter name.
     * @param array<string, mixed> $draws In-body draws as `draw#N` pseudo-arguments.
     */
    public function __construct(
        public string $propertyId,
        public int $attempt,
        public array $arguments,
        public array $draws,
        public ?\Throwable $failure,
        public int $elapsedNs,
    ) {}
}
