<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * The adaptive example database behind targeted search: the best-scoring
 * inputs of every {@see \Rasuvaeff\PropertyTesting\Target} label, kept from
 * one run to the next so the search resumes where it got to instead of
 * climbing from scratch every time. A separate optional seam — a
 * {@see Corpus} that also implements it keeps the search document apart from
 * the regression document, so an older reader never mistakes a best-scoring
 * input for a regression and never prunes it as one.
 *
 * @psalm-type Targets = array<string, array{direction: TargetDirection, entries: list<array{score: float, arguments: array<string, mixed>}>}>
 *
 * @api
 */
interface SearchCorpus
{
    /**
     * The stored best inputs of $id, by label, for the parameters the property
     * currently has. An entry recorded under other parameter names, or a label
     * stored with the opposite direction, is not returned.
     *
     * @param string $id The property id.
     * @param list<string> $parameterNames The property's current parameters, in order.
     *
     * @return Targets
     */
    public function recallTargets(string $id, array $parameterNames): array;

    /**
     * Replace the stored best inputs of $id with $targets — the whole
     * document, since the pool it comes from already merged what was recalled.
     *
     * @param string $id The property id.
     * @param Targets $targets The pool, by label.
     * @param list<string> $parameterNames The property's current parameters, in order.
     */
    public function rememberTargets(string $id, array $targets, array $parameterNames): void;
}
