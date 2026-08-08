<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * Thrown (as the failure of an otherwise passing property) when a coverage
 * requirement registered via {@see Classify::cover()} is not met: the property
 * held on every run, but the generators did not exercise a labelled case often
 * enough, so the pass would be (partially) vacuous.
 *
 * @api
 */
final class CoverageViolationException extends \RuntimeException {}
