<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * A random-phase attempt was discarded via Assume — neither a failure nor a
 * successful check.
 *
 * @api
 */
final readonly class RunDiscarded implements PropertyEvent
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
    ) {}
}
