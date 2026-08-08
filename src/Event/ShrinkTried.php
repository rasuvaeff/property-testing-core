<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * One shrink trial executed the body with a smaller candidate for a single
 * parameter (or `draw#N` tape position).
 *
 * @api
 */
final readonly class ShrinkTried implements PropertyEvent
{
    public function __construct(
        public string $propertyId,
        public string $parameter,
        public mixed $candidate,
        public bool $accepted,
    ) {}
}
