<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\GaveUpException;

/**
 * Discards exceeded the budget before the requested checks completed.
 *
 * @api
 */
final readonly class GaveUp implements PropertyResult
{
    public function __construct(
        public GaveUpException $exception,
        public RunStatistics $statistics,
    ) {}

    #[\Override]
    public function failure(): \Throwable
    {
        return $this->exception;
    }
}
