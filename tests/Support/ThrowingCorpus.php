<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Runner\Corpus;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;

/**
 * A corpus whose backend is down: every operation throws, the way a Redis
 * client refusing the connection would.
 */
final class ThrowingCorpus implements Corpus
{
    /** @var list<string> */
    public array $calls = [];

    #[\Override]
    public function recall(string $id, array $parameterNames): array
    {
        $this->calls[] = 'recall';

        throw new \RuntimeException('corpus backend is down');
    }

    #[\Override]
    public function remember(string $id, CounterExample $counterExample, array $parameterNames): void
    {
        $this->calls[] = 'remember';

        throw new \RuntimeException('corpus backend is down');
    }

    #[\Override]
    public function prune(string $id, CorpusEntry $entry): void
    {
        $this->calls[] = 'prune';

        throw new \RuntimeException('corpus backend is down');
    }
}
