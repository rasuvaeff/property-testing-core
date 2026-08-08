<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Reported when a recorded regression fails again: the minimised input of an
 * earlier failure, replayed from the on-disk corpus (`PROPERTY_DB`) before the
 * random phase.
 *
 * The input is already minimal — it is the shrunk counterexample of the run that
 * recorded it — so it is replayed once and reported verbatim, without shrinking.
 * A regression stored as a bare seed instead of values replays the whole random
 * phase and reports the usual {@see PropertyViolationException}.
 *
 * @api
 */
final class RegressionViolationException extends RuntimeException
{
    /**
     * @param array<string, mixed> $arguments The recorded input, keyed by parameter name.
     * @param int $seed Seed of the run that originally recorded this regression.
     * @param ?\Throwable $failure The assertion or exception the replay raised.
     */
    public function __construct(
        private readonly array $arguments,
        private readonly int $seed,
        ?\Throwable $failure = null,
    ) {
        $message = sprintf(
            'Recorded regression failed (originally found with seed %d): %s',
            $seed,
            $this->format($arguments),
        );

        if ($failure instanceof \Throwable) {
            $message .= sprintf("\n  Failure:  %s", $failure->getMessage());
        }

        parent::__construct($message, previous: $failure);
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function format(array $arguments): string
    {
        $parts = [];

        /** @var mixed $value */
        foreach ($arguments as $name => $value) {
            $parts[] = $name . '=' . ValueRenderer::render($value);
        }

        return implode(', ', $parts);
    }
}
