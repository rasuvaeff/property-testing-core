<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\StateMachine;

/**
 * Marks a public method of a rule-based machine as an invariant: it runs
 * before the first step and after every executed one, and an exception it
 * throws falsifies the sequence at that step. Takes no parameters — it reads
 * the machine's own state and the system under test it holds.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Invariant {}
