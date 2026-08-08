<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Thrown (as the failure of a property) when discarded inputs exceed the
 * configured budget before the requested number of successful checks completes.
 * It exposes successful, discarded and total attempt counts so the result cannot
 * hide a weak input distribution.
 *
 * The fix is almost always to construct valid inputs directly (e.g.
 * {@see Gen::flatMap()} / {@see Gen::draw()}) rather than generating broadly and
 * discarding, so runs are valid by construction.
 *
 * @api
 */
final class GaveUpException extends RuntimeException
{
    public function __construct(
        public readonly string $propertyName,
        public readonly int $requiredRuns,
        public readonly int $successfulRuns,
        public readonly int $discardedRuns,
        public readonly int $attempts,
        public readonly int $maxDiscards,
    ) {
        parent::__construct(sprintf(
            'Property "%s" gave up after %d attempt(s): %d/%d successful run(s), %d discarded (maximum %d). '
            . 'Narrow or construct the generators so inputs are valid by construction.',
            $propertyName,
            $attempts,
            $successfulRuns,
            $requiredRuns,
            $discardedRuns,
            $maxDiscards,
        ));
    }
}
