<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner\Redis;

use Predis\ClientInterface;

/**
 * {@see CorpusClient} over predis.
 *
 * @api
 */
final readonly class PredisCorpusClient implements CorpusClient
{
    /**
     * @param ClientInterface $client A connected client.
     */
    public function __construct(
        private ClientInterface $client,
    ) {}

    #[\Override]
    public function get(string $key): ?string
    {
        /** @var mixed $document */
        $document = $this->client->executeCommand(
            $this->client->createCommand('GET', [$key]),
        );

        return is_string($document) ? $document : null;
    }

    #[\Override]
    public function compareAndSet(string $key, ?string $expected, ?string $document): bool
    {
        // createCommand/executeCommand are real ClientInterface methods; the
        // magic get()/eval() @method annotations are not resolvable by psalm
        // across every supported predis release.
        /** @var mixed $written */
        $written = $this->client->executeCommand(
            $this->client->createCommand('EVAL', [CorpusScript::CAS, 1, $key, $expected ?? '', $document ?? '']),
        );

        return (int) $written === 1;
    }
}
