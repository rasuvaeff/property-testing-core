<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\PathViolationException;

/**
 * A run pinned to a shrink path found its falsification but could not follow
 * the recorded descent. Its own outcome, deliberately: falling back to a fresh
 * search would report a counterexample the path never reached, and reporting a
 * pass would hide a property that did fail.
 *
 * @api
 */
final readonly class PathFailed implements PropertyResult
{
    /**
     * @param PathViolationException $exception Which step of the pinned path could not be
     *        followed, and why.
     */
    public function __construct(
        public PathViolationException $exception,
    ) {}

    #[\Override]
    public function failure(): \Throwable
    {
        return $this->exception;
    }
}
