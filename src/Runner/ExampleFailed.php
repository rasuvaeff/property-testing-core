<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\ExampleViolationException;

/**
 * An explicit example failed before the random phase. Examples are already
 * minimal, so the input is reported verbatim, without shrinking.
 *
 * @api
 */
final readonly class ExampleFailed implements PropertyResult
{
    public function __construct(
        public ExampleViolationException $exception,
    ) {}

    #[\Override]
    public function failure(): \Throwable
    {
        return $this->exception;
    }
}
