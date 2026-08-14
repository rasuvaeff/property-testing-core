<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\RedisCorpus;
use Rasuvaeff\PropertyTesting\Tests\Support\InMemoryCorpusClient;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The Redis backend against an in-memory client.
 *
 * Two things are pinned here that a live-server test would pin worse: the
 * document is the SAME one the filesystem backend writes (so a corpus is
 * portable between the two, which is the whole reason the codec was extracted),
 * and the optimistic write really re-reads and retries — a live Redis will not
 * lose a compare-and-set on demand, an in-memory double will.
 */
#[Test]
#[Covers(RedisCorpus::class)]
final class RedisCorpusTest
{
    private const string ID = 'X::y';

    public function recallIsEmptyWithoutARecord(): void
    {
        Assert::same($this->corpus(new InMemoryCorpusClient())->recall(self::ID, ['x']), []);
    }

    public function remembersTheMinimisedInputAsAValuesEntry(): void
    {
        $client = new InMemoryCorpusClient();
        $corpus = $this->corpus($client);

        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 4242), ['x']);

        $entries = $corpus->recall(self::ID, ['x']);
        Assert::same(count($entries), 1);
        Assert::true($entries[0]->isValues());
        Assert::same($entries[0]->arguments, ['x' => 51]);
        Assert::same($entries[0]->seed, 4242);
    }

    public function theStoredDocumentIsTheOneTheFilesystemBackendWrites(): void
    {
        // The portability claim, asserted rather than described: same bytes,
        // same key (the sha1 of the property id, under the prefix).
        $client = new InMemoryCorpusClient();
        $this->corpus($client)->remember(self::ID, $this->counterExample(['x' => 51], 4242), ['x']);

        $directory = sys_get_temp_dir() . '/prop-corpus-' . bin2hex(random_bytes(6));
        (new FilesystemCorpus($directory))->remember(self::ID, $this->counterExample(['x' => 51], 4242), ['x']);

        $file = $directory . '/' . sha1(self::ID) . '.json';
        $onDisk = (string) file_get_contents($file);
        @unlink($file);
        @unlink($file . '.lock');
        @rmdir($directory);

        Assert::same($client->documents['property-testing:corpus:' . sha1(self::ID)], $onDisk);
    }

    public function anEmptyCorpusIsAnAbsentKey(): void
    {
        // Pruning the last entry deletes the key rather than storing an empty
        // document — the same shape the filesystem backend has, where an empty
        // corpus is a missing file.
        $client = new InMemoryCorpusClient();
        $corpus = $this->corpus($client);
        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        $entries = $corpus->recall(self::ID, ['x']);
        Assert::same(count($entries), 1);

        $corpus->prune(self::ID, $entries[0]);

        Assert::same($client->documents, []);
        Assert::same($corpus->recall(self::ID, ['x']), []);
    }

    public function accumulatesSeveralDistinctFailuresAndDedupesTheSameOne(): void
    {
        $corpus = $this->corpus(new InMemoryCorpusClient());

        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);
        $corpus->remember(self::ID, $this->counterExample(['x' => 77], 2), ['x']);
        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 3), ['x']);

        $entries = $corpus->recall(self::ID, ['x']);

        Assert::same(count($entries), 2);
        // Newest first, and the re-recorded input is one entry carrying the
        // newest seed rather than two.
        Assert::same(array_map(static fn(CorpusEntry $e): mixed => $e->arguments['x'], $entries), [51, 77]);
        Assert::same($entries[0]->seed, 3);
    }

    public function capsEntriesAtTheConfiguredSize(): void
    {
        $corpus = new RedisCorpus(new InMemoryCorpusClient(), maxValues: 2, maxSeeds: 1);

        foreach ([1, 2, 3] as $x) {
            $corpus->remember(self::ID, $this->counterExample(['x' => $x], $x), ['x']);
        }

        $entries = $corpus->recall(self::ID, ['x']);

        Assert::same(count($entries), 2);
        Assert::same(array_map(static fn(CorpusEntry $e): mixed => $e->arguments['x'], $entries), [3, 2]);
    }

    public function aValuesEntryWhoseSignatureChangedIsSkipped(): void
    {
        $corpus = $this->corpus(new InMemoryCorpusClient());
        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        // The property gained a parameter: the stored input is a different
        // input now, and replaying it would feed the body something else.
        Assert::same($corpus->recall(self::ID, ['x', 'y']), []);
    }

    public function anUnreadableDocumentIsAnEmptyCorpus(): void
    {
        $client = new InMemoryCorpusClient();
        $client->documents['property-testing:corpus:' . sha1(self::ID)] = 'not json at all';

        // A corrupt corpus must not fail a run that would otherwise pass.
        Assert::same($this->corpus($client)->recall(self::ID, ['x']), []);
    }

    public function aWriteThatLosesTheRaceIsRetried(): void
    {
        // Two attempts lost, the third wins: the entry is stored, and the
        // client saw three writes.
        $client = new InMemoryCorpusClient(failNextWrites: 2);
        $corpus = $this->corpus($client);

        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        Assert::same($client->writes, 3);
        Assert::same(count($corpus->recall(self::ID, ['x'])), 1);
    }

    public function aWriteThatKeepsLosingGivesUpQuietly(): void
    {
        // A corpus is memory, not a ledger: under a storm of writers it loses
        // an entry rather than failing a test run that has already passed.
        $client = new InMemoryCorpusClient(failNextWrites: RedisCorpus::MAX_ATTEMPTS + 1);
        $corpus = $this->corpus($client);

        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        Assert::same($client->writes, RedisCorpus::MAX_ATTEMPTS);
        Assert::same($corpus->recall(self::ID, ['x']), []);
    }

    public function pruningAnEntryThatIsNotStoredLeavesTheRest(): void
    {
        $client = new InMemoryCorpusClient();
        $corpus = $this->corpus($client);
        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        $corpus->prune(self::ID, CorpusEntry::values(['x' => 999], 7));

        Assert::same(count($corpus->recall(self::ID, ['x'])), 1);
    }

    public function seedEntriesFallBackAndPruneBySeed(): void
    {
        $corpus = $this->corpus(new InMemoryCorpusClient());
        // An object argument cannot be stored as values, so the entry is a seed.
        $corpus->remember(self::ID, $this->counterExample(['x' => new \stdClass()], 99), ['x']);

        $entries = $corpus->recall(self::ID, ['x']);
        Assert::same(count($entries), 1);
        Assert::false($entries[0]->isValues());
        Assert::same($entries[0]->seed, 99);

        $corpus->prune(self::ID, $entries[0]);

        Assert::same($corpus->recall(self::ID, ['x']), []);
    }

    public function theKeyPrefixIsConfigurable(): void
    {
        $client = new InMemoryCorpusClient();
        $corpus = new RedisCorpus($client, prefix: 'suite-42:');

        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        Assert::same(array_keys($client->documents), ['suite-42:' . sha1(self::ID)]);
    }

    public function anUnusableEntryDoesNotHideTheOnesBehindIt(): void
    {
        // A corrupt entry in the middle of a document must be skipped, not
        // treated as the end of the corpus — the entries after it are the
        // regressions that would silently stop replaying.
        $client = new InMemoryCorpusClient();
        $corpus = $this->corpus($client);
        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        $key = 'property-testing:corpus:' . sha1(self::ID);
        /** @var array{format: int, property: string, entries: list<array<string, mixed>>} $document */
        $document = json_decode($client->documents[$key], associative: true, flags: JSON_THROW_ON_ERROR);
        array_unshift($document['entries'], ['kind' => 'values', 'seed' => 'not an int']);
        $client->documents[$key] = json_encode($document, JSON_THROW_ON_ERROR);

        $entries = $corpus->recall(self::ID, ['x']);

        Assert::same(count($entries), 1);
        Assert::same($entries[0]->arguments, ['x' => 51]);
    }

    public function valuesEntriesComeBackBeforeSeedEntries(): void
    {
        // Cheapest first: a values entry costs one run, a seed entry costs the
        // whole random phase.
        $corpus = $this->corpus(new InMemoryCorpusClient());
        $corpus->remember(self::ID, $this->counterExample(['x' => new \stdClass()], 1), ['x']);
        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 2), ['x']);

        $entries = $corpus->recall(self::ID, ['x']);

        Assert::same(count($entries), 2);
        Assert::true($entries[0]->isValues());
        Assert::false($entries[1]->isValues());
    }

    public function pruningFromTheMiddleLeavesAList(): void
    {
        // The kept entries must stay a list: a gapped array encodes as a JSON
        // object, which is no longer the document the filesystem backend writes.
        $client = new InMemoryCorpusClient();
        $corpus = $this->corpus($client);

        foreach ([1, 2, 3] as $x) {
            $corpus->remember(self::ID, $this->counterExample(['x' => $x], $x), ['x']);
        }

        $entries = $corpus->recall(self::ID, ['x']);
        Assert::same(count($entries), 3);

        $corpus->prune(self::ID, $entries[1]);

        Assert::same(
            array_map(static fn(CorpusEntry $e): mixed => $e->arguments['x'], $corpus->recall(self::ID, ['x'])),
            [3, 1],
        );

        // And the document still holds a JSON array. Dropping the middle entry
        // without reindexing leaves gaps, which json_encode renders as an
        // object — readable by this decoder, and no longer the document the
        // filesystem backend writes.
        /** @var array{entries: mixed} $document */
        $document = json_decode(
            $client->documents['property-testing:corpus:' . sha1(self::ID)],
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        Assert::true(is_array($document['entries']) && array_is_list($document['entries']));
    }

    private function corpus(InMemoryCorpusClient $client): RedisCorpus
    {
        return new RedisCorpus($client);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function counterExample(array $arguments, int $seed): CounterExample
    {
        return new CounterExample(
            seed: $seed,
            runsBeforeFailure: 0,
            originalArguments: $arguments,
            shrunkArguments: $arguments,
        );
    }
}
