<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\Command\RawCommand;

/**
 * A predis client that records every command and answers from a script: each
 * response is returned in order, a Throwable is thrown instead.
 */
final class RecordingPredisClient implements ClientInterface
{
    /**
     * @var list<array{string, list<mixed>}>
     */
    public array $commands = [];

    /**
     * @param list<mixed> $responses
     */
    public function __construct(
        private array $responses,
    ) {}

    #[\Override]
    public function getCommandFactory(): never
    {
        throw new \LogicException('Not used by PredisCorpusClient');
    }

    #[\Override]
    public function getOptions(): never
    {
        throw new \LogicException('Not used by PredisCorpusClient');
    }

    #[\Override]
    public function connect(): never
    {
        throw new \LogicException('Not used by PredisCorpusClient');
    }

    #[\Override]
    public function disconnect(): never
    {
        throw new \LogicException('Not used by PredisCorpusClient');
    }

    #[\Override]
    public function getConnection(): never
    {
        throw new \LogicException('Not used by PredisCorpusClient');
    }

    #[\Override]
    public function createCommand($method, $arguments = []): CommandInterface
    {
        return new RawCommand($method, $arguments);
    }

    #[\Override]
    public function executeCommand(CommandInterface $command): mixed
    {
        $this->commands[] = [$command->getId(), array_values($command->getArguments())];

        if ($this->responses === []) {
            throw new \LogicException('No scripted response left');
        }

        /** @var mixed $response */
        $response = array_shift($this->responses);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    #[\Override]
    public function __call($method, $arguments): never
    {
        throw new \LogicException('Not used by PredisCorpusClient');
    }
}
