<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Reported when a run pinned to a shrink path cannot follow it: the candidate
 * a step names is gone, no longer differs from the value it would replace, or
 * no longer falsifies the property.
 *
 * A path indexes into each node's shrink candidates, so any edit to a
 * generator — even reordering the candidates of one — orphans it. That is the
 * expected end of a path's life, and it is reported rather than absorbed: had
 * the runner fallen back to a fresh search, the answer would look exactly like
 * a successful replay while reproducing a different descent.
 *
 * The property still failed when this is raised; what failed is the
 * reproduction, not the property. For a regression that has to survive a
 * refactor, record it in the corpus instead.
 *
 * @api
 */
final class PathViolationException extends RuntimeException
{
    /**
     * @param string $path The path the run was pinned to.
     * @param int $step One-based position of the segment that could not be followed.
     * @param string $segment The segment itself, in its `name:index` form.
     * @param string $reason Why it could not be followed, as a noun phrase.
     */
    public function __construct(
        private readonly string $path,
        private readonly int $step,
        private readonly string $segment,
        string $reason,
    ) {
        parent::__construct(sprintf(
            'Shrink path "%s" no longer applies: step %d ("%s") %s. Re-run without a path to search '
                . 'for the counterexample again',
            $path,
            $step,
            $segment,
            $reason,
        ));
    }

    /**
     * The path the run was pinned to, as it was configured.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * One-based position of the segment that could not be followed.
     */
    public function getStep(): int
    {
        return $this->step;
    }

    /**
     * The segment that could not be followed, in its `name:index` form.
     */
    public function getSegment(): string
    {
        return $this->segment;
    }
}
