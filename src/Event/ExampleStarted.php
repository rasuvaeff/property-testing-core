<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * An explicit example is about to execute, before the random phase.
 *
 * @api
 */
final readonly class ExampleStarted implements PropertyEvent
{
    /**
     * @param list<mixed> $arguments Positional, as the examples method yielded them.
     */
    public function __construct(
        public string $propertyId,
        public int $index,
        public array $arguments,
    ) {}
}
