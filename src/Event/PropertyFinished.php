<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * The property finished. A null $failure is a pass; otherwise the failure is
 * the outcome's exception (falsified, gave up, coverage, deadline, budget,
 * generation exhausted, example or regression violation).
 *
 * @api
 */
final readonly class PropertyFinished implements PropertyEvent
{
    public function __construct(
        public string $propertyId,
        public ?\Throwable $failure,
    ) {}
}
