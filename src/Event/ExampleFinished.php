<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * An explicit example finished. A null $failure means it passed or was
 * discarded via Assume.
 *
 * @api
 */
final readonly class ExampleFinished implements PropertyEvent
{
    /**
     * @param list<mixed> $arguments
     */
    public function __construct(
        public string $propertyId,
        public int $index,
        public array $arguments,
        public ?\Throwable $failure,
    ) {}
}
