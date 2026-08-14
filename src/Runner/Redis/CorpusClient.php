<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner\Redis;

/**
 * The two operations {@see \Rasuvaeff\PropertyTesting\Runner\RedisCorpus} needs
 * from a Redis client, and nothing else.
 *
 * The seam exists for three reasons, in order of how much they cost when it is
 * missing: the two PHP clients disagree about how commands are called (phpredis
 * has real methods, predis routes them through `__call` annotations that no
 * static analyser resolves across releases); the corpus is then unit-testable
 * against an in-memory double instead of only against a live server; and a
 * consumer with a connection pool, a namespaced wrapper or a test harness can
 * supply its own without this package knowing about it.
 *
 * @api
 */
interface CorpusClient
{
    /**
     * The stored document for $key, or null when the key does not exist.
     *
     * @param string $key The corpus key, already namespaced by the caller.
     */
    public function get(string $key): ?string;

    /**
     * Stores $document for $key only if the stored value is still $expected —
     * the whole read-modify-write of a corpus write, made safe without holding
     * a lock across a round trip.
     *
     * @param string $key The corpus key, already namespaced by the caller.
     * @param ?string $expected The document the caller read, or null when the key was absent.
     * @param ?string $document The document to store, or null to delete the key (an empty corpus
     *        is an absent key, exactly as an empty corpus is an absent file on disk).
     *
     * @return bool Whether the write happened; false means someone else wrote first and the
     *         caller must re-read.
     */
    public function compareAndSet(string $key, ?string $expected, ?string $document): bool;
}
