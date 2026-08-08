<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Thrown when a bounded-attempt generator cannot produce a value that satisfies
 * its constraint within its attempt budget: {@see \Rasuvaeff\PropertyTesting\Arbitrary\FilteredArbitrary}
 * whose predicate rejected every draw, or a sized collection
 * ({@see \Rasuvaeff\PropertyTesting\Arbitrary\DictionaryArbitrary},
 * {@see \Rasuvaeff\PropertyTesting\Arbitrary\UniqueArrayArbitrary},
 * {@see \Rasuvaeff\PropertyTesting\Arbitrary\CommandSequenceArbitrary}) that
 * could not reach its declared minimum.
 *
 * A generator NEVER yields a value outside its declared domain — it fails loudly
 * with this exception instead, so a property never silently receives an
 * out-of-domain input. Exhaustion can be transient (a satisfiable-but-rare
 * predicate) or structural (a domain too small to ever meet the minimum); the
 * message describes what happened and how to widen the domain.
 *
 * @api
 */
final class GenerationExhausted extends RuntimeException
{
    /**
     * @param string $arbitrary Human-readable label of the generator that gave up (e.g. `Gen::filter()`).
     * @param int $attempts Attempt budget spent before giving up.
     * @param string $reason What could not be satisfied and how to fix it.
     */
    public function __construct(
        public readonly string $arbitrary,
        public readonly int $attempts,
        string $reason,
    ) {
        parent::__construct(sprintf(
            '%s exhausted after %d attempt(s): %s',
            $arbitrary,
            $attempts,
            $reason,
        ));
    }
}
