<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Thrown (as the failure of a property) when a single run's body takes longer
 * than the {@see Property::$timeoutMs} deadline. The offending input is the
 * counterexample: it is pathological for the code under test (catastrophic
 * regex, deep recursion, unbounded backoff) — or the deadline is too tight.
 *
 * The input is reported as-is, NOT shrunk: shrink acceptance would have to
 * re-measure wall time, and timing noise makes that descent non-deterministic.
 *
 * @api
 */
final class DeadlineExceededException extends RuntimeException
{
    /**
     * @param array<string, mixed> $arguments The run's generated arguments (including `draw#N` pseudo-arguments).
     * @param float $elapsedMs Measured wall-clock duration of the run.
     * @param int $timeoutMs The configured per-run deadline.
     */
    public function __construct(
        public readonly string $propertyName,
        public readonly array $arguments,
        public readonly float $elapsedMs,
        public readonly int $timeoutMs,
    ) {
        parent::__construct(sprintf(
            'Property "%s" run exceeded its %d ms deadline (took %.1f ms) for %s. '
            . 'The input is pathological for the code under test, or the deadline is too tight.',
            $propertyName,
            $timeoutMs,
            $elapsedMs,
            $this->formatArguments($arguments),
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function formatArguments(array $arguments): string
    {
        if ($arguments === []) {
            return '(no arguments)';
        }

        $pairs = array_map(
            static fn(mixed $value, mixed $name): string => $name . '=' . ValueRenderer::render($value),
            $arguments,
            array_keys($arguments),
        );

        return implode(', ', $pairs);
    }
}
