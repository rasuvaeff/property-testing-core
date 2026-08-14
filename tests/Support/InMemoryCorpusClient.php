<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

use Rasuvaeff\PropertyTesting\Runner\Redis\CorpusClient;

/**
 * A {@see CorpusClient} in an array, with the one behaviour a live Redis has
 * that a naive double does not: the compare-and-set can be made to lose.
 *
 * `failNextWrites` makes that many writes report contention regardless of what
 * is stored, which is how the retry loop is driven — otherwise the loop's
 * second iteration and its give-up branch are unreachable in a unit test, and
 * unreachable code is exactly where a corpus quietly stops recording.
 */
final class InMemoryCorpusClient implements CorpusClient
{
    /** @var array<string, string> */
    public array $documents = [];

    /** Writes attempted, successful or not — the retry loop's cost, visible. */
    public int $writes = 0;

    public function __construct(
        private int $failNextWrites = 0,
    ) {}

    #[\Override]
    public function get(string $key): ?string
    {
        return $this->documents[$key] ?? null;
    }

    #[\Override]
    public function compareAndSet(string $key, ?string $expected, ?string $document): bool
    {
        ++$this->writes;

        if ($this->failNextWrites > 0) {
            --$this->failNextWrites;

            return false;
        }

        if (($this->documents[$key] ?? null) !== $expected) {
            return false;
        }

        if ($document === null) {
            unset($this->documents[$key]);

            return true;
        }

        $this->documents[$key] = $document;

        return true;
    }
}
