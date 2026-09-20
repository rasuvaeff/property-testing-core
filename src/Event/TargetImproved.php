<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

use Rasuvaeff\PropertyTesting\Runner\TargetDirection;

/**
 * A passing run scored better on a {@see \Rasuvaeff\PropertyTesting\Target}
 * label than any before it — in the random phase or the search phase. The
 * arguments are the input that did it, so a listener can watch the search
 * close in on an extreme.
 *
 * @api
 */
final readonly class TargetImproved implements PropertyEvent
{
    /**
     * @param ?float $previous The best before this run; null for the first score of the label.
     * @param array<string, mixed> $arguments The input that scored, by parameter name.
     */
    public function __construct(
        public string $propertyId,
        public string $label,
        public TargetDirection $direction,
        public float $score,
        public ?float $previous,
        public array $arguments,
    ) {}
}
