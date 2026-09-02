<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner\Redis;

/**
 * A {@see PhpRedisCorpusClient} that connects on first use.
 *
 * {@see PhpRedisCorpusClient} takes an already-connected `\Redis` — the right
 * contract for a class that does not own the connection — but resolving a
 * DSN must not open a socket. A suite that names a corpus it never touches
 * (every property pinned by an explicit seed, say) would otherwise fail at
 * startup against a server it never needed, and CI proved it: the eager
 * version was red everywhere the extension was installed and Redis was not
 * running.
 *
 * predis is lazy by construction, so only this side needed the wrapper.
 *
 * @api
 */
final class LazyPhpRedisCorpusClient implements CorpusClient
{
    private ?PhpRedisCorpusClient $client = null;

    /**
     * @param RedisDsn $dsn Where to connect on first use.
     */
    public function __construct(
        private readonly RedisDsn $dsn,
    ) {}

    /**
     * @throws \RuntimeException When the first use cannot connect or select the database.
     */
    #[\Override]
    public function get(string $key): ?string
    {
        return $this->client()->get($key);
    }

    /**
     * @throws \RuntimeException When the first use cannot connect or select the database.
     */
    #[\Override]
    public function compareAndSet(string $key, ?string $expected, ?string $document): bool
    {
        return $this->client()->compareAndSet($key, $expected, $document);
    }

    /**
     * @throws \RuntimeException
     */
    private function client(): PhpRedisCorpusClient
    {
        if ($this->client instanceof PhpRedisCorpusClient) {
            return $this->client;
        }

        $redis = new \Redis();

        // A refused connection is a configuration error, not a corpus that is
        // silently empty: the suite was told to share its memory.
        if (!$redis->connect($this->dsn->phpRedisHost(), $this->dsn->port)) {
            throw new \RuntimeException(sprintf('Could not connect to the Redis corpus at %s:%d', $this->dsn->host, $this->dsn->port));
        }

        if ($this->dsn->database !== RedisDsn::DEFAULT_DATABASE && !$redis->select($this->dsn->database)) {
            throw new \RuntimeException(sprintf('Could not select Redis database %d for the corpus', $this->dsn->database));
        }

        return $this->client = new PhpRedisCorpusClient($redis);
    }
}
