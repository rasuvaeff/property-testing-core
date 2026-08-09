<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Runner\Corpus;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;

/**
 * In-memory corpus double: recalls a fixed entry list and records every
 * remember/prune call, for asserting on the runner's corpus protocol without
 * touching the filesystem.
 */
final class RecordingCorpus implements Corpus
{
    /** @var list<array{string, CounterExample, list<string>}> */
    public array $remembered = [];

    /** @var list<CorpusEntry> */
    public array $pruned = [];

    /**
     * @param list<CorpusEntry> $entries
     */
    public function __construct(
        private readonly array $entries = [],
    ) {}

    #[\Override]
    public function recall(string $id, array $parameterNames): array
    {
        return $this->entries;
    }

    #[\Override]
    public function remember(string $id, CounterExample $counterExample, array $parameterNames): void
    {
        $this->remembered[] = [$id, $counterExample, $parameterNames];
    }

    #[\Override]
    public function prune(string $id, CorpusEntry $entry): void
    {
        $this->pruned[] = $entry;
    }
}
