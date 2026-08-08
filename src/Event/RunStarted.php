<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * A random-phase attempt is about to execute the property body.
 *
 * @api
 */
final readonly class RunStarted implements PropertyEvent
{
    /**
     * @param array<string, mixed> $arguments Generated inputs, keyed by parameter name.
     */
    public function __construct(
        public string $propertyId,
        public int $attempt,
        public array $arguments,
    ) {}
}
