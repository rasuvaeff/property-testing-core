<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\Runner\Redis\CorpusClient;
use Rasuvaeff\PropertyTesting\Runner\Redis\LazyPhpRedisCorpusClient;
use Rasuvaeff\PropertyTesting\Runner\Redis\PredisCorpusClient;
use Rasuvaeff\PropertyTesting\Runner\Redis\RedisDsn;

/**
 * The corpus a `PROPERTY_DB` value names.
 *
 * A plain path is a {@see FilesystemCorpus}; `redis://` and `rediss://` are a
 * {@see RedisCorpus} over `ext-redis` when it is loaded, predis otherwise;
 * any other scheme is refused. The adapter reads the environment (the engine
 * never does — golden rule) and hands the value here, so the two adapters
 * mean the same thing by the same DSN.
 *
 * ```php
 * $corpus = CorpusFactory::fromDsn('/tmp/corpus');
 * $corpus = CorpusFactory::fromDsn('redis://redis:6379/2?prefix=suite-a:');
 * $corpus = CorpusFactory::fromDsn('redis://redis:6379', $password);
 * ```
 *
 * @api
 */
final class CorpusFactory
{
    /**
     * A leading URI scheme: `redis` in `redis://host`. A value without one is
     * a directory path; a value with a scheme that is not `redis`/`rediss` is
     * a typo (`Rediss://`, `resis://`) or the wrong backend — never a
     * directory.
     */
    private const string SCHEME_PATTERN = '#^([a-zA-Z][a-zA-Z0-9+.\-]*)://#';

    /**
     * One corpus per distinct DSN. Resolving happens once per property, so
     * without this a suite sharing a Redis corpus would build a client — and
     * open a connection on first recall — for every property.
     *
     * @var array<string, Corpus>
     */
    private static array $cache = [];

    private function __construct()
    {
        // Static helper; not instantiable.
    }

    /**
     * The corpus for a `PROPERTY_DB` value — the same instance for the same
     * value *and password* within a process.
     *
     * @param string $dsn A directory path, or a `redis://` / `rediss://` DSN.
     * @param ?string $password The `AUTH` password for a Redis DSN, read from the environment by
     *        the adapter (`PROPERTY_DB_PASSWORD`). Ignored for a directory; empty means none.
     *
     * @throws \InvalidArgumentException For a scheme that is not redis/rediss, an unusable Redis
     *         DSN, or a Redis DSN without any Redis client available.
     */
    public static function fromDsn(string $dsn, ?string $password = null): Corpus
    {
        // The password is part of the identity: two suites pointed at the same
        // server as different users are two corpora, and a cache keyed by the
        // DSN alone would hand the second one the first one's connection.
        return self::$cache[$dsn . "\0" . ($password ?? '')] ??= self::build($dsn, $password);
    }

    private static function build(string $dsn, ?string $password): Corpus
    {
        if (preg_match(self::SCHEME_PATTERN, $dsn, $matches) !== 1) {
            return new FilesystemCorpus($dsn);
        }

        if (!in_array(strtolower($matches[1]), ['redis', 'rediss'], strict: true)) {
            // Not a directory: a suite told to share its corpus, quietly
            // writing to a directory nobody reads, is worse than one that
            // stops. The DSN itself is not echoed — it may carry credentials.
            throw new \InvalidArgumentException(sprintf(
                'PROPERTY_DB uses an unsupported scheme "%s://"; use redis:// (or rediss:// for TLS) for a shared corpus or a plain directory path for a local one',
                $matches[1],
            ));
        }

        $parsed = RedisDsn::parse($dsn, $password);

        return new RedisCorpus(self::client($parsed, $dsn), $parsed->prefix);
    }

    /**
     * A client for the DSN, preferring `ext-redis` when it is loaded because
     * it needs no autoloaded dependency at all.
     *
     * Neither available is a configuration error, not a silent fall back to
     * the filesystem: a suite told to share its corpus and quietly writing to
     * a directory nobody reads is worse than one that stops.
     */
    private static function client(RedisDsn $dsn, string $raw): CorpusClient
    {
        if (extension_loaded('redis')) {
            // Lazily: resolving a DSN must not open a socket, or a suite that
            // names a corpus it never touches fails at startup.
            return new LazyPhpRedisCorpusClient($dsn);
        }

        if (class_exists(\Predis\Client::class)) {
            return new PredisCorpusClient(new \Predis\Client($dsn->toPredisParameters()));
        }

        throw new \InvalidArgumentException(sprintf(
            'PROPERTY_DB="%s" needs a Redis client: install ext-redis or require predis/predis',
            $raw,
        ));
    }
}
