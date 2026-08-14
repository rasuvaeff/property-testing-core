<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Integration;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\Redis\PredisCorpusClient;
use Rasuvaeff\PropertyTesting\Runner\RedisCorpus;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * {@see RedisCorpus} against a real server, which is the only place two claims
 * can actually be checked: that the compare-and-set is atomic on Redis rather
 * than only in a double, and that a document written here is the very document
 * the filesystem backend writes.
 *
 * Skipped unless `REDIS_HOST` is set:
 *
 * ```bash
 * docker run -d --name property-redis -p 6379:6379 redis:7-alpine
 * REDIS_HOST=127.0.0.1 vendor/bin/testo --suite=Integration
 * ```
 */
#[Test]
#[CoversNothing]
final class RedisIntegrationTest
{
    private const string ID = 'Integration::redis';

    public function aCounterexampleSurvivesARoundTripThroughRedis(): void
    {
        $client = $this->client();

        if ($client === null) {
            return;
        }

        $corpus = new RedisCorpus($client, prefix: $this->prefix());

        $corpus->remember(self::ID, $this->counterExample(['x' => 51], 4242), ['x']);

        $entries = $corpus->recall(self::ID, ['x']);
        Assert::same(count($entries), 1);
        Assert::same($entries[0]->arguments, ['x' => 51]);
        Assert::same($entries[0]->seed, 4242);

        $corpus->prune(self::ID, $entries[0]);
        Assert::same($corpus->recall(self::ID, ['x']), []);
    }

    public function theStoredDocumentIsTheOneTheFilesystemBackendWrites(): void
    {
        $client = $this->client();

        if ($client === null) {
            return;
        }

        $prefix = $this->prefix();
        $corpus = new RedisCorpus($client, prefix: $prefix);
        $corpus->remember(self::ID, $this->counterExample(['x' => 7], 1), ['x']);

        $directory = sys_get_temp_dir() . '/prop-corpus-' . bin2hex(random_bytes(6));
        (new FilesystemCorpus($directory))->remember(self::ID, $this->counterExample(['x' => 7], 1), ['x']);
        $file = $directory . '/' . sha1(self::ID) . '.json';
        $onDisk = (string) file_get_contents($file);
        @unlink($file);
        @unlink($file . '.lock');
        @rmdir($directory);

        Assert::same($client->get($prefix . sha1(self::ID)), $onDisk);

        $corpus->prune(self::ID, $corpus->recall(self::ID, ['x'])[0]);
    }

    public function aConcurrentWriterLosesTheCompareAndSetRatherThanTheEntry(): void
    {
        $client = $this->client();

        if ($client === null) {
            return;
        }

        $key = $this->prefix() . sha1(self::ID);

        // The document another writer stored between our read and our write.
        Assert::true($client->compareAndSet($key, null, '{"format":1,"property":"x","entries":[]}'));

        // A stale expectation must be refused, and the stored document left
        // alone — that is the whole guarantee the Lua script exists for.
        Assert::false($client->compareAndSet($key, null, '{"clobbered":true}'));
        Assert::same($client->get($key), '{"format":1,"property":"x","entries":[]}');

        Assert::true($client->compareAndSet($key, '{"format":1,"property":"x","entries":[]}', null));
        Assert::null($client->get($key));
    }

    private function client(): ?PredisCorpusClient
    {
        $host = getenv('REDIS_HOST');

        if ($host === false || $host === '') {
            return null;
        }

        $port = getenv('REDIS_PORT');

        return new PredisCorpusClient(new \Predis\Client([
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port === false || $port === '' ? 6379 : (int) $port,
        ]));
    }

    /**
     * A prefix unique to this process, so a shared server (a developer's own
     * Redis, a CI service reused by parallel jobs) cannot make one run's
     * leftovers another run's failure.
     */
    private function prefix(): string
    {
        return 'property-testing-it:' . getmypid() . ':';
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
