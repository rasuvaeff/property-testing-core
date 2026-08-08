<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\GenerationExhausted;

/**
 * A generator could not produce a valid value (e.g. a filter whose predicate
 * rejected every draw) — a clean failure instead of an uncaught crash.
 *
 * @api
 */
final readonly class GenerationFailed implements PropertyResult
{
    public function __construct(
        public GenerationExhausted $exception,
    ) {}

    #[\Override]
    public function failure(): \Throwable
    {
        return $this->exception;
    }
}
