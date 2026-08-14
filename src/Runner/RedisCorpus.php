<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Internal\CorpusDocument;
use Rasuvaeff\PropertyTesting\Runner\Redis\CorpusClient;

/**
 * A {@see Corpus} in Redis, so one falsification is replayed everywhere.
 *
 * {@see FilesystemCorpus} remembers a counterexample for whoever owns that
 * directory — which, in CI, is a machine that is deleted when the job ends.
 * The same corpus in Redis is shared: a failure found on a laptop replays in
 * CI, a failure found in CI replays on the next laptop, and neither had to
 * commit anything.
 *
 * The document is byte-identical to the one on disk, so the two backends store
 * the same artifact and moving between them is a copy rather than a migration.
 *
 * ```php
 * $corpus = new RedisCorpus(new PhpRedisCorpusClient($redis));
 * (new PropertyRunner())->run($definition, $executor, corpus: $corpus);
 * ```
 *
 * Two differences from the filesystem backend are worth knowing before wiring
 * it into a suite:
 *
 * - **a shared corpus is a shared value space.** A values entry is generator
 *   output, so anyone who can read the Redis instance can read the inputs that
 *   falsified a property. That is the same warning the directory carries, with
 *   a wider audience;
 * - **writes are optimistic, not locked.** A write re-reads and retries when
 *   another writer got there first ({@see MAX_ATTEMPTS} times), then gives up
 *   silently. A corpus is memory, not a ledger: losing one entry to a storm of
 *   concurrent writers costs a replay, while throwing would fail a test run
 *   that had already passed.
 *
 * @api
 */
final readonly class RedisCorpus implements Corpus
{
    /**
     * How many times a write re-reads and retries before giving up. Contention
     * here is one property's key being written by two runs at the same moment;
     * a handful of attempts covers that, and a number large enough to cover a
     * pathological storm would only make the run slower without making the
     * corpus more correct.
     */
    public const int MAX_ATTEMPTS = 5;

    /**
     * @param CorpusClient $client The Redis client seam — {@see \Rasuvaeff\PropertyTesting\Runner\Redis\PhpRedisCorpusClient}
     *        for `ext-redis`, {@see \Rasuvaeff\PropertyTesting\Runner\Redis\PredisCorpusClient} for predis, or your own.
     * @param string $prefix Key prefix, so a corpus can share an instance with everything else that
     *        lives there. The key is this prefix plus the sha1 of the property id, exactly as the
     *        filesystem backend names its files.
     * @param int $maxValues How many values entries a property keeps, oldest evicted first. The
     *        default matches the filesystem backend; a shared corpus may reasonably keep more.
     * @param int $maxSeeds How many seed entries a property keeps, oldest evicted first.
     */
    public function __construct(
        private CorpusClient $client,
        private string $prefix = 'property-testing:corpus:',
        private int $maxValues = 8,
        private int $maxSeeds = 2,
    ) {}

    /**
     * @param string $id The property id, as the runner knows it.
     * @param list<string> $parameterNames The property's current parameters, in order.
     *
     * @return list<CorpusEntry>
     */
    #[\Override]
    public function recall(string $id, array $parameterNames): array
    {
        $values = [];
        $seeds = [];

        foreach ($this->read($id) as $raw) {
            $entry = CorpusDocument::hydrate($raw, $parameterNames, FilesystemCorpus::SEQUENCE_EPOCH);

            if (!$entry instanceof CorpusEntry) {
                continue;
            }

            if ($entry->isValues()) {
                $values[] = $entry;
            } else {
                $seeds[] = $entry;
            }
        }

        return [...$values, ...$seeds];
    }

    /**
     * @param string $id The property id, as the runner knows it.
     * @param CounterExample $counterExample The failure to record.
     * @param list<string> $parameterNames The property's current parameters, in order.
     */
    #[\Override]
    public function remember(string $id, CounterExample $counterExample, array $parameterNames): void
    {
        $entry = CorpusDocument::encodeEntry($counterExample, $parameterNames, FilesystemCorpus::SEQUENCE_EPOCH);
        $key = CorpusDocument::keyOf($entry);

        $this->rewrite(
            $id,
            // Newest first, without the previous copy of the same input.
            fn(array $stored): array => CorpusDocument::cap(
                [$entry, ...array_filter($stored, static fn(array $raw): bool => CorpusDocument::keyOf($raw) !== $key)],
                $this->maxValues,
                $this->maxSeeds,
            ),
        );
    }

    /**
     * @param string $id The property id, as the runner knows it.
     * @param CorpusEntry $entry The entry whose replay no longer fails.
     */
    #[\Override]
    public function prune(string $id, CorpusEntry $entry): void
    {
        // A hydrated entry re-encodes to the very bytes it was read from, so the
        // key identifies the same stored entry.
        $encoded = $entry->isValues()
            ? CorpusDocument::valuesEntry($entry->arguments, array_keys($entry->arguments), $entry->seed, FilesystemCorpus::SEQUENCE_EPOCH)
            : CorpusDocument::seedEntry($entry->seed, FilesystemCorpus::SEQUENCE_EPOCH);
        $key = $encoded === null ? null : CorpusDocument::keyOf($encoded);

        $this->rewrite(
            $id,
            static fn(array $stored): array => array_values(array_filter(
                $stored,
                static fn(array $raw): bool => CorpusDocument::keyOf($raw) !== $key,
            )),
        );
    }

    /**
     * Read, transform, write — retried while another writer wins the race.
     *
     * @param \Closure(list<array<string, mixed>>): list<array<string, mixed>> $change
     */
    private function rewrite(string $id, \Closure $change): void
    {
        $key = $this->key($id);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $stored = $this->client->get($key);
            $entries = $change($stored === null ? [] : CorpusDocument::decode($stored, FilesystemCorpus::FORMAT_VERSION));

            $document = $entries === []
                ? null
                : CorpusDocument::encode($id, $entries, FilesystemCorpus::FORMAT_VERSION);

            if ($this->client->compareAndSet($key, $stored, $document)) {
                return;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $id): array
    {
        $document = $this->client->get($this->key($id));

        return $document === null ? [] : CorpusDocument::decode($document, FilesystemCorpus::FORMAT_VERSION);
    }

    private function key(string $id): string
    {
        return $this->prefix . sha1($id);
    }
}
