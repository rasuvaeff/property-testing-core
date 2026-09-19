<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Thrown by {@see Assume::that()} to discard the current property run without
 * counting it as a failure or a successful check.
 *
 * Part of the executor seam: a {@see Runner\TrialExecutor} catches it and
 * returns {@see Runner\TrialOutcome::discarded()}, which is what
 * {@see Runner\CallableTrialExecutor} and both adapters do. It does not
 * implement {@see PropertyTestingException} on purpose — it is a control-flow
 * signal, not a failure, and a body catching the marker must not swallow it.
 *
 * @api
 */
final class AssumptionSkipped extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Assumption not satisfied; property run discarded');
    }
}
