<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * Decorates a {@see TrialExecutor} so that PHP diagnostics of the given levels
 * (`E_WARNING`, `E_DEPRECATED`, …) raised while the body runs become an
 * `\ErrorException` thrown at the place they were raised — a failing run the
 * engine shrinks like any other, instead of a line in a log that a green
 * suite never looks at.
 *
 * ```php
 * $executor = new FailOnTrialExecutor($executor, E_WARNING | E_DEPRECATED);
 * ```
 *
 * The handler is installed around each execution and restored after it, and
 * a diagnostic it does not claim goes on to whatever handler was installed
 * before — a framework's own deprecation collector keeps working. An explicit
 * level is allowed to see what the suite's `error_reporting()` masks (notably
 * `E_DEPRECATED` and `E_NOTICE`), but a diagnostic silenced with `@` stays
 * silent: the operator changes `error_reporting()` for the handler, and that
 * change is what is detected.
 *
 * The throw happens inside the decorated executor's call, so the executor
 * folds it into its outcome exactly as it folds any other exception from the
 * body.
 *
 * @api
 */
final readonly class FailOnTrialExecutor implements TrialExecutor
{
    /**
     * @param TrialExecutor $inner The executor that runs the body.
     * @param int $levels A bitmask of `E_*` levels that fail the run; 0 changes nothing.
     *
     * @throws \InvalidArgumentException When the mask is negative.
     */
    public function __construct(
        private TrialExecutor $inner,
        private int $levels,
    ) {
        if ($levels < 0) {
            throw new \InvalidArgumentException(sprintf('Fail-on levels must be greater than or equal to 0, got %d', $levels));
        }
    }

    /**
     * Runs the decorated executor with the handler installed.
     *
     * @param array<string, mixed> $arguments Passed through unchanged.
     */
    #[\Override]
    public function execute(array $arguments): TrialOutcome
    {
        return self::guard($this->levels, fn(): TrialOutcome => $this->inner->execute($arguments));
    }

    /**
     * Runs one call under the same handler, for an executor that cannot be
     * decorated because the call it guards sits inside its own error handling
     * (the Testo adapter folds a throw from its pipeline into the run's result
     * and has to see the `\ErrorException` there).
     *
     * @template TResult
     *
     * @param int $levels A bitmask of `E_*` levels that throw; 0 runs the call unguarded.
     * @param \Closure(): TResult $call The one call to guard.
     *
     * @throws \ErrorException From inside the call, for a diagnostic of a claimed level.
     *
     * @return TResult
     */
    public static function guard(int $levels, \Closure $call): mixed
    {
        if ($levels === 0) {
            return $call();
        }

        $reportingAtStart = error_reporting();
        // Filled in once set_error_handler() reports what was installed before.
        /** @var callable|null $previous */
        $previous = null;
        $handler = static function (
            int $severity,
            string $message,
            string $file,
            int $line,
        ) use (&$previous, $reportingAtStart, $levels): bool {
            $suppressed = ($reportingAtStart !== error_reporting()) && (($severity & error_reporting()) === 0);

            if (!$suppressed && ($severity & $levels) !== 0) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }

            if (is_callable($previous)) {
                return (bool) $previous($severity, $message, $file, $line);
            }

            return false;
        };
        $previous = set_error_handler($handler);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
