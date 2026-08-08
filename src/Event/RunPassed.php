<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * A random-phase attempt completed without failing.
 *
 * @api
 */
final readonly class RunPassed implements PropertyEvent
{
    /**
     * @param array<string, mixed> $arguments Generated inputs, keyed by parameter name.
     * @param array<string, mixed> $draws In-body draws as `draw#N` pseudo-arguments.
     * @param list<string> $labels Classification labels the run recorded.
     */
    public function __construct(
        public string $propertyId,
        public int $attempt,
        public array $arguments,
        public array $draws,
        public array $labels,
        public int $elapsedNs,
    ) {}
}
