<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * Every check completed and every coverage requirement held.
 *
 * @api
 */
final readonly class Passed implements PropertyResult
{
    public function __construct(
        public RunStatistics $statistics,
    ) {}

    #[\Override]
    public function failure(): ?\Throwable
    {
        return null;
    }
}
