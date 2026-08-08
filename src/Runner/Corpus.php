<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\CounterExample;

/**
 * Persistent store of past falsifications, replayed before the random phase.
 *
 * The runner takes it explicitly (`?Corpus`): no store means no replay and no
 * filesystem access at all. Resolving where the corpus lives (the
 * `PROPERTY_DB` environment variable under Testo) is the adapter's job.
 *
 * @api
 */
interface Corpus
{
    /**
     * The usable entries recorded for the property, cheapest first. Entries
     * that can no longer replay faithfully (foreign format, superseded
     * sequence epoch, a signature that no longer matches) are skipped, not
     * surfaced.
     *
     * @param list<string> $parameterNames The property's current parameters, in order.
     * @return list<CorpusEntry>
     */
    public function recall(string $id, array $parameterNames): array;

    /**
     * Records a falsification's counterexample as the newest entry, preferring
     * its minimised arguments over the bare seed.
     *
     * @param list<string> $parameterNames The property's current parameters, in order.
     */
    public function remember(string $id, CounterExample $counterExample, array $parameterNames): void;

    /**
     * Drops an entry whose replay no longer fails — the regression is fixed
     * and the entry has served its purpose.
     */
    public function prune(string $id, CorpusEntry $entry): void;
}
