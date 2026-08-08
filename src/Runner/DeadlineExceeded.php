<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\DeadlineExceededException;

/**
 * A single run (random, example or regression replay) took longer than the
 * per-run deadline. The offending input is reported unshrunk.
 *
 * @api
 */
final readonly class DeadlineExceeded implements PropertyResult
{
    public function __construct(
        public DeadlineExceededException $exception,
    ) {}

    #[\Override]
    public function failure(): \Throwable
    {
        return $this->exception;
    }
}
