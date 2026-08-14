<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * How hard the runner works to minimise a counterexample.
 *
 * {@see PropertyConfig::$maxShrinks} caps the *accepted* steps; the cost of a
 * descent is in the candidates it *tries*, which on large collections easily
 * exceeds the random phase that found the failure. These modes bound that cost
 * from the other side.
 *
 * The mode is derived, never set twice: a phase set without
 * {@see Phase::Shrink} is {@see self::Off}, and a configured
 * {@see PropertyConfig::$shrinkBudgetMs} is {@see self::Bounded}.
 *
 * @api
 */
enum ShrinkMode
{
    /**
     * No descent at all: the counterexample is reported exactly as generated,
     * with zero shrink steps and zero shrink trials.
     */
    case Off;

    /**
     * The descent runs until its wall-clock budget expires, then returns the
     * best candidate found so far. Deliberately not reproducible across
     * machines — see {@see PropertyConfig::$shrinkBudgetMs}.
     */
    case Bounded;

    /**
     * The descent runs to exhaustion, subject only to
     * {@see PropertyConfig::$maxShrinks}. The default, and the only
     * deterministic mode.
     */
    case Full;
}
