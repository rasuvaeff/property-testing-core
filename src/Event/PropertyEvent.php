<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * Marker for the engine's lifecycle events.
 *
 * Events carry engine data only — property id, seed, attempt numbers,
 * arguments, labels, elapsed time, failures, counterexamples. Framework types
 * (Testo's TestInfo/TestResult/Messenger, PHPUnit's) never appear here.
 *
 * @api
 */
interface PropertyEvent {}
