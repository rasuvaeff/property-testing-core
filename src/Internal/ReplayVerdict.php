<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\Runner\PropertyResult;

/**
 * What replaying one recorded regression settled, when it did not produce a
 * {@see PropertyResult} to report.
 *
 * @internal
 */
enum ReplayVerdict
{
    /**
     * The recorded input ran and no longer falsifies the property: the entry
     * has served its purpose and is pruned.
     */
    case Stale;

    /**
     * The body never judged the input — the environment skipped the run. The
     * entry says nothing about this environment and is kept: pruning it here
     * would delete the counterexample for every other one.
     */
    case Inconclusive;
}
