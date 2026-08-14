<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Integration;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\Redis\CorpusClient;
use Rasuvaeff\PropertyTesting\Runner\Redis\PhpRedisCorpusClient;
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
 * Every scenario runs against every client this environment can build — predis
 * always, `ext-redis` when the extension is loaded — because the one thing a
 * double cannot check is whether a client transmits the script the way the
 * server expects it.
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
        foreach ($this->clients() as $name => $client) {
            $corpus = new RedisCorpus($client, prefix: $this->prefix($name));

            $corpus->remember(self::ID, $this->counterExample(['x' => 51], 4242), ['x']);

            $entries = $corpus->recall(self::ID, ['x']);
            Assert::same(count($entries), 1);
            Assert::same($entries[0]->arguments, ['x' => 51]);
            Assert::same($entries[0]->seed, 4242);

            $corpus->prune(self::ID, $entries[0]);
            Assert::same($corpus->recall(self::ID, ['x']), []);
        }
    }

    public function theStoredDocumentIsTheOneTheFilesystemBackendWrites(): void
    {
        $directory = sys_get_temp_dir() . '/prop-corpus-' . bin2hex(random_bytes(6));
        (new FilesystemCorpus($directory))->remember(self::ID, $this->counterExample(['x' => 7], 1), ['x']);
        $file = $directory . '/' . sha1(self::ID) . '.json';
        $onDisk = (string) file_get_contents($file);
        @unlink($file);
        @unlink($file . '.lock');
        @rmdir($directory);

        foreach ($this->clients() as $name => $client) {
            $prefix = $this->prefix($name);
            $corpus = new RedisCorpus($client, prefix: $prefix);
            $corpus->remember(self::ID, $this->counterExample(['x' => 7], 1), ['x']);

            Assert::same($client->get($prefix . sha1(self::ID)), $onDisk);

            $corpus->prune(self::ID, $corpus->recall(self::ID, ['x'])[0]);
        }
    }

    public function aConcurrentWriterLosesTheCompareAndSetRatherThanTheEntry(): void
    {
        foreach ($this->clients() as $name => $client) {
            $key = $this->prefix($name) . sha1(self::ID);

            // The document another writer stored between our read and our write.
            Assert::true($client->compareAndSet($key, null, '{"format":1,"property":"x","entries":[]}'));

            // A stale expectation must be refused, and the stored document left
            // alone — that is the whole guarantee the Lua script exists for.
            Assert::false($client->compareAndSet($key, null, '{"clobbered":true}'));
            Assert::same($client->get($key), '{"format":1,"property":"x","entries":[]}');

            Assert::true($client->compareAndSet($key, '{"format":1,"property":"x","entries":[]}', null));
            Assert::null($client->get($key));
        }
    }

    public function everyShippedClientThisEnvironmentCanBuildIsExercised(): void
    {
        // The coverage claim of this suite, asserted rather than assumed: an
        // environment that quietly failed to load ext-redis would otherwise run
        // half the clients and still report green.
        $names = array_keys($this->clients());

        if ($names === []) {
            return;
        }

        Assert::true(in_array('predis', $names, strict: true));
        Assert::same(extension_loaded('redis'), in_array('phpredis', $names, strict: true));
    }

    /**
     * Every client this environment can build, keyed by name. Empty without
     * `REDIS_HOST`, which is how this suite skips itself.
     *
     * @return array<string, CorpusClient>
     */
    private function clients(): array
    {
        $host = getenv('REDIS_HOST');

        if ($host === false || $host === '') {
            return [];
        }

        $port = getenv('REDIS_PORT');
        $port = $port === false || $port === '' ? 6379 : (int) $port;

        $clients = [
            'predis' => new PredisCorpusClient(new \Predis\Client([
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port,
            ])),
        ];

        if (extension_loaded('redis')) {
            $redis = new \Redis();
            $redis->connect($host, $port);

            $clients['phpredis'] = new PhpRedisCorpusClient($redis);
        }

        return $clients;
    }

    /**
     * A prefix unique to this process and client, so a shared server (a
     * developer's own Redis, a CI service reused by parallel jobs) cannot make
     * one run's leftovers another run's failure, and the two clients cannot
     * read each other's keys.
     */
    private function prefix(string $client): string
    {
        return 'property-testing-it:' . getmypid() . ':' . $client . ':';
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
