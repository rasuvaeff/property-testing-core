<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * Minimal failing input for a property, captured at falsification time.
 *
 * Carries both the original (randomly generated) counterexample and the
 * shrunk (minimised) one, plus the seed needed to reproduce the run.
 *
 * @api
 */
final readonly class CounterExample
{
    /**
     * @param int $seed Seed of the run that first failed (pass it to {@see Property} to reproduce).
     * @param int $runsBeforeFailure Number of successful (non-discarded) runs before the failure.
     * @param array<string, mixed> $originalArguments Randomly generated arguments that first failed.
     * @param array<string, mixed> $shrunkArguments Minimised arguments that still fail.
     * @param int $shrinkSteps Number of accepted shrink steps between the original and the minimised arguments.
     * @param ?\Throwable $failure The assertion or exception reported by the failing run.
     * @param int $skips Number of runs discarded via {@see Assume::that()} before the failure.
     * @param int $shrinkTrials Total number of shrink candidates tried (accepted and rejected).
     */
    public function __construct(
        public int $seed,
        public int $runsBeforeFailure,
        public array $originalArguments,
        public array $shrunkArguments,
        public int $shrinkSteps = 0,
        public ?\Throwable $failure = null,
        public int $skips = 0,
        public int $shrinkTrials = 0,
    ) {}

    /**
     * Machine-readable representation suitable for reporters and serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'runsBeforeFailure' => $this->runsBeforeFailure,
            'originalArguments' => \Rasuvaeff\PropertyTesting\ValueRenderer::normalize($this->originalArguments),
            'shrunkArguments' => \Rasuvaeff\PropertyTesting\ValueRenderer::normalize($this->shrunkArguments),
            'shrinkSteps' => $this->shrinkSteps,
            'shrinkTrials' => $this->shrinkTrials,
            'failure' => $this->failure instanceof \Throwable
                ? ['type' => $this->failure::class, 'message' => $this->failure->getMessage()]
                : null,
            'skips' => $this->skips,
        ];
    }

    public function toJson(bool $pretty = false): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | ($pretty ? JSON_PRETTY_PRINT : 0),
        );
    }

    public function toExamplesCode(string $methodName = 'propertyExamples'): string
    {
        if (preg_match('/^[A-Za-z_]\w*\z/', $methodName) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid examples method name "%s"', $methodName));
        }

        $arguments = \Rasuvaeff\PropertyTesting\ValueRenderer::exportPhp(array_values($this->shrunkArguments));

        return sprintf(
            "public static function %s(): iterable\n{\n    yield 'shrunk seed %d' => %s;\n}",
            $methodName,
            $this->seed,
            $arguments,
        );
    }
}
