<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Thrown by {@see Assume::that()} to discard the current property run without
 * counting it as a failure or a successful check.
 *
 * @internal
 */
final class AssumptionSkipped extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Assumption not satisfied; property run discarded');
    }
}
