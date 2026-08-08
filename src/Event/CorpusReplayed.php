<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * A recorded regression entry is about to replay before the random phase.
 *
 * @api
 */
final readonly class CorpusReplayed implements PropertyEvent
{
    /**
     * @param array<string, mixed> $arguments The recorded input for a values
     *   entry; empty for a seed entry (the seed reproduces the whole phase).
     */
    public function __construct(
        public string $propertyId,
        public bool $isValues,
        public array $arguments,
        public int $seed,
    ) {}
}
