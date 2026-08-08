<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use RuntimeException;

/**
 * Reported when a property is falsified.
 *
 * Carries the {@see CounterExample} so reporters (and tests of this package)
 * can inspect the seed and the shrunk arguments. Its message renders a
 * human-readable summary.
 *
 * @api
 */
final class PropertyViolationException extends RuntimeException
{
    public function __construct(private readonly CounterExample $counterExample)
    {
        parent::__construct($this->render($counterExample), previous: $counterExample->failure);
    }

    public function getCounterExample(): CounterExample
    {
        return $this->counterExample;
    }

    private function render(CounterExample $c): string
    {
        $original = $this->format($c->originalArguments);
        $shrunk = $this->format($c->shrunkArguments);

        $message = sprintf(
            "Property falsified after %d successful run(s); seed=%d\n  Original: %s\n  Shrunk:   %s (%d shrink step(s)%s)",
            $c->runsBeforeFailure,
            $c->seed,
            $original,
            $shrunk,
            $c->shrinkSteps,
            $c->shrinkTrials > 0 ? sprintf(', %d trial(s)', $c->shrinkTrials) : '',
        );

        $diff = $this->diff($c->originalArguments, $c->shrunkArguments);

        if ($diff !== '') {
            $message .= sprintf("\n  Changed:  %s", $diff);
        }

        if ($c->failure instanceof \Throwable) {
            $message .= sprintf("\n  Failure:  %s", $c->failure->getMessage());
        }

        return $message;
    }

    /**
     * `name: before -> after` for every argument whose rendered value differs
     * between the original and the shrunk counterexample; unchanged arguments
     * are omitted. An in-body draw can appear on one side only (the tape grows
     * or truncates during shrinking) — the missing side renders as `(absent)`.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $shrunk
     */
    private function diff(array $original, array $shrunk): string
    {
        $parts = [];

        foreach (array_keys($original + $shrunk) as $name) {
            $before = array_key_exists($name, $original) ? ValueRenderer::render($original[$name]) : '(absent)';
            $after = array_key_exists($name, $shrunk) ? ValueRenderer::render($shrunk[$name]) : '(absent)';

            if ($before !== $after) {
                $parts[] = sprintf('%s=%s -> %s', $name, $before, $after);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function format(array $arguments): string
    {
        $pairs = array_map(
            static fn(mixed $value, mixed $name): string => $name . '=' . ValueRenderer::render($value),
            $arguments,
            array_keys($arguments),
        );

        return implode(', ', $pairs);
    }
}
