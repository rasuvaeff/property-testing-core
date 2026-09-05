<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Thrown (as the failure of a property) when discarded inputs — or runs the
 * environment refused — exceed their budget before the requested number of
 * successful checks completes. It exposes successful, discarded, skipped and
 * total attempt counts so the result cannot hide a weak input distribution.
 *
 * The two budgets are separate, and so are the two messages. A discard is a
 * statement about the input, and the fix is almost always to construct valid
 * inputs directly (e.g. {@see Gen::flatMap()} / {@see Gen::draw()}) rather than
 * generating broadly and discarding. A skip is a statement about the machine,
 * where narrowing the generators would achieve nothing at all.
 *
 * @api
 */
final class GaveUpException extends RuntimeException
{
    /**
     * @param string $propertyName Property that gave up, as the adapter named it.
     * @param int $requiredRuns Successful checks the configuration asked for.
     * @param int $successfulRuns Successful checks completed before the budget ran out.
     * @param int $discardedRuns Runs discarded via `Assume::that()` — the input left the domain.
     * @param int $attempts Bodies executed in total, discarded and skipped ones included.
     * @param int $maxDiscards Cap each of the two budgets was measured against.
     * @param int $skippedRuns Runs the environment refused (a missing dependency, a skipped
     *        lifecycle hook).
     * @param bool $exhaustedBySkips Which budget ran out: the skips' one, or the discards'. It
     *        selects the message, because the two have no advice in common.
     */
    public function __construct(
        public readonly string $propertyName,
        public readonly int $requiredRuns,
        public readonly int $successfulRuns,
        public readonly int $discardedRuns,
        public readonly int $attempts,
        public readonly int $maxDiscards,
        public readonly int $skippedRuns = 0,
        public readonly bool $exhaustedBySkips = false,
    ) {
        parent::__construct($exhaustedBySkips
            ? sprintf(
                'Property "%s" gave up after %d attempt(s): %d/%d successful run(s), %d skipped (maximum %d). '
                . 'The environment refused those runs, so the generators are not the cause: '
                . 'a missing dependency or a lifecycle hook skipped this property more often than it checked it.',
                $propertyName,
                $attempts,
                $successfulRuns,
                $requiredRuns,
                $skippedRuns,
                $maxDiscards,
            )
            : sprintf(
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
