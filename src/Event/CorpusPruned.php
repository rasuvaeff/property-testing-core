<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * A recorded entry no longer fails and was removed from the corpus.
 *
 * @api
 */
final readonly class CorpusPruned implements PropertyEvent
{
    public function __construct(
        public string $propertyId,
        public bool $isValues,
        public int $seed,
    ) {}
}
