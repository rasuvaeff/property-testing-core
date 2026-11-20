<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Thrown out of {@see \Rasuvaeff\PropertyTesting\Runner\PropertyRunner::run()}
 * when a generator itself throws while producing an input — a bug in a
 * `Gen::map()`/`flatMap()` closure, a class constructor rejecting a drawn
 * value — so the failure still names the seed and the attempt that reproduce it.
 *
 * It is not a property outcome: the body never ran on that input, so there is
 * nothing to shrink and no {@see \Rasuvaeff\PropertyTesting\Runner\PropertyResult}
 * to return. The generator's own exception is {@see self::getPrevious()}.
 * {@see GenerationExhaustedException} keeps its own clean result
 * ({@see \Rasuvaeff\PropertyTesting\Runner\GenerationFailed}) and never ends up here.
 *
 * @api
 */
final class GenerationErrorException extends RuntimeException implements PropertyTestingException
{
    /**
     * @param string $propertyName The property whose input could not be generated.
     * @param int $seed The seed of the phase that drew the input; replaying it reproduces the error.
     * @param int $attempt The 1-based attempt the input was drawn for.
     * @param \Throwable $previous What the generator threw.
     */
    public function __construct(
        public readonly string $propertyName,
        public readonly int $seed,
        public readonly int $attempt,
        \Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'Property "%s": a generator threw while drawing the input of attempt %d; seed=%d' . "\n  Error:    %s: %s",
                $propertyName,
                $attempt,
                $seed,
                $previous::class,
                $previous->getMessage(),
            ),
            previous: $previous,
        );
    }
}
