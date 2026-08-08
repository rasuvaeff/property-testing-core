<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\PropertyViolationException;

/**
 * A random run falsified the property; the counterexample is shrunk.
 *
 * @api
 */
final readonly class Falsified implements PropertyResult
{
    public function __construct(
        public PropertyViolationException $exception,
    ) {}

    public function counterExample(): CounterExample
    {
        return $this->exception->getCounterExample();
    }

    #[\Override]
    public function failure(): \Throwable
    {
        return $this->exception;
    }
}
