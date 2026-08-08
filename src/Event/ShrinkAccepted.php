<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * A shrink candidate still failed and became the new current counterexample.
 *
 * @api
 */
final readonly class ShrinkAccepted implements PropertyEvent
{
    public function __construct(
        public string $propertyId,
        public int $step,
        public string $parameter,
        public mixed $before,
        public mixed $after,
    ) {}
}
