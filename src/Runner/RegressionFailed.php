<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\RegressionViolationException;

/**
 * A recorded regression (a values corpus entry) failed again on replay. The
 * input was already minimal when recorded, so it is reported verbatim.
 *
 * @api
 */
final readonly class RegressionFailed implements PropertyResult
{
    public function __construct(
        public RegressionViolationException $exception,
    ) {}

    #[\Override]
    public function failure(): \Throwable
    {
        return $this->exception;
    }
}
