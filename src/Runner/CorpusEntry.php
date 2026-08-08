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
     */
    private function __construct(
        public ?array $arguments,
        public int $seed,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public static function values(array $arguments, int $seed): self
    {
        return new self($arguments, $seed);
    }

    public static function seed(int $seed): self
    {
        return new self(null, $seed);
    }

    /**
     * @psalm-assert-if-true array<string, mixed> $this->arguments
     */
    public function isValues(): bool
    {
        return $this->arguments !== null;
    }
}
