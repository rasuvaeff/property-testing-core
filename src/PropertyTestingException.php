<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * Marker for every exception this engine reports, so a caller can catch the
 * package as a whole (`catch (PropertyTestingException $e)`) without
 * enumerating the ten concrete types.
 *
 * It is deliberately empty: the concrete classes keep their own fields and
 * message formats, and each still extends `\RuntimeException`.
 *
 * {@see AssumptionSkipped} does not implement it. That exception is a
 * control-flow signal — a discarded run, not a failure — and a property body
 * catching the marker must not swallow its own discards.
 *
 * @api
 */
interface PropertyTestingException extends \Throwable {}
