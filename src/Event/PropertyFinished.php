<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

use Rasuvaeff\PropertyTesting\Runner\DistributionReport;

/**
 * The property finished. A null $failure is a pass; otherwise the failure is
 * the outcome's exception (falsified, gave up, coverage, deadline, budget,
 * generation exhausted, example or regression violation).
 *
 * @api
 */
final readonly class PropertyFinished implements PropertyEvent
{
    /**
     * @param ?DistributionReport $distribution What the random phase generated, as data — the
     *        labels with their shares, the `cover()` thresholds beside them, the discards. Null for
     *        an outcome that carries no counters: a falsification stops at the counterexample, and
     *        an example, regression, deadline or generation failure never reached the random phase's
     *        accounting. Listeners are where telemetry lives, which is why the report travels here
     *        and not only on the result.
     */
    public function __construct(
        public string $propertyId,
        public ?\Throwable $failure,
        public ?DistributionReport $distribution = null,
    ) {}
}
