<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

use Rasuvaeff\PropertyTesting\CounterExample;

/**
 * A falsification's minimised counterexample was recorded in the corpus.
 *
 * @api
 */
final readonly class CorpusStored implements PropertyEvent
{
    public function __construct(
        public string $propertyId,
        public CounterExample $counterExample,
    ) {}
}
