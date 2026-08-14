<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner\Redis;

/**
 * {@see CorpusClient} over the `ext-redis` client (`\Redis`).
 *
 * @api
 */
final readonly class PhpRedisCorpusClient implements CorpusClient
{
    /**
     * @param \Redis $client A connected client. Its serializer and prefix options are the
     *        caller's business: this class stores a JSON string under the key it is given.
     */
    public function __construct(
        private \Redis $client,
    ) {}

    #[\Override]
    public function get(string $key): ?string
    {
        /** @var mixed $document */
        $document = $this->client->get($key);

        return is_string($document) ? $document : null;
    }

    #[\Override]
    public function compareAndSet(string $key, ?string $expected, ?string $document): bool
    {
        /** @var mixed $written */
        $written = $this->client->eval(CorpusScript::CAS, [$key, $expected ?? '', $document ?? ''], 1);

        return (int) $written === 1;
    }
}
