<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * One recorded regression in a property's corpus: either the minimised failing
 * input itself (a "values" entry — replayed as a single run, immune to changes in
 * the generation sequence) or the seed of the run that failed (a "seed" entry —
 * the fallback for inputs no {@see \Rasuvaeff\PropertyTesting\Internal\ValueCodec} can represent, replayed by
 * re-running the whole random phase).
 *
 * @api
 */
final readonly class CorpusEntry
{
    /**
     * @param ?array<string, mixed> $arguments Minimised input keyed by parameter name; null for a seed entry.
     * @param ?int $runsBeforeFailure How many runs the recorded failure survived before falsifying,
     *        so a seed replay with a lowered runs count still reaches the failing attempt. Null for
     *        values entries and for seed entries recorded before the field existed.
     */
    private function __construct(
        public ?array $arguments,
        public int $seed,
        public ?int $runsBeforeFailure = null,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public static function values(array $arguments, int $seed): self
    {
        return new self($arguments, $seed);
    }

    /**
     * @param ?int $runsBeforeFailure Runs the recorded failure survived; null when unknown.
     */
    public static function seed(int $seed, ?int $runsBeforeFailure = null): self
    {
        return new self(null, $seed, $runsBeforeFailure);
    }

    /**
     * @psalm-assert-if-true array<string, mixed> $this->arguments
     */
    public function isValues(): bool
    {
        return $this->arguments !== null;
    }
}
