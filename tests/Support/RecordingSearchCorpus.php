<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Runner\Corpus;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Rasuvaeff\PropertyTesting\Runner\SearchCorpus;

/**
 * A corpus that also stores targets, in memory, for the search-phase tests.
 *
 * @psalm-import-type Targets from SearchCorpus
 */
final class RecordingSearchCorpus implements Corpus, SearchCorpus
{
    /** @var list<Targets> */
    public array $rememberedTargets = [];

    public int $recalls = 0;

    /**
     * @param Targets $targets
     */
    public function __construct(
        private readonly array $targets = [],
        private readonly ?\Throwable $recallFailure = null,
    ) {}

    #[\Override]
    public function recall(string $id, array $parameterNames): array
    {
        return [];
    }

    #[\Override]
    public function remember(string $id, CounterExample $counterExample, array $parameterNames): void {}

    #[\Override]
    public function prune(string $id, CorpusEntry $entry): void {}

    #[\Override]
    public function recallTargets(string $id, array $parameterNames): array
    {
        ++$this->recalls;

        if ($this->recallFailure instanceof \Throwable) {
            throw $this->recallFailure;
        }

        return $this->targets;
    }

    #[\Override]
    public function rememberTargets(string $id, array $targets, array $parameterNames): void
    {
        $this->rememberedTargets[] = $targets;
    }
}
