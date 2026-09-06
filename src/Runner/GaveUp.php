<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\GaveUpException;

/**
 * Discarded inputs — or runs the environment refused — exceeded their budget
 * before the requested checks completed. {@see GaveUpException::$exhaustedBySkips}
 * says which of the two ran out.
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
