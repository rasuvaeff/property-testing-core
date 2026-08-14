<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * The stages of one property run, as an explicit set rather than a fixed
 * sequence. Selecting a subset trades coverage for time on purpose: replaying
 * only the corpus and the pinned examples turns a minutes-long suite into a
 * seconds-long pull-request gate, and dropping {@see self::Corpus} measures a
 * property honestly without deleting the recorded regressions to do it.
 *
 * Whatever the subset, the stages that do run keep this order.
 *
 * @api
 */
enum Phase
{
    /** The fixed argument tuples of {@see PropertyDefinition::$examples}. */
    case Examples;

    /**
     * Replay of the recorded regressions. Composes with
     * {@see PropertyDefinition::$replayRegressions} as an AND — either one
     * switching it off is enough. Gates replay only: a fresh falsification is
     * still recorded, because storing is not a phase.
     */
    case Corpus;

    /** Generated inputs — the random phase proper. */
    case Random;

    /**
     * Minimisation of a counterexample. Leaving it out is exactly
     * {@see ShrinkMode::Off}; the two are one behaviour with one
     * implementation.
     */
    case Shrink;

    /**
     * Every phase, in run order — the default of {@see PropertyConfig}.
     *
     * @return non-empty-list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
