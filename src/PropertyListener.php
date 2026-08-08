<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use Rasuvaeff\PropertyTesting\Event\PropertyEvent;

/**
 * Observer of a property run's lifecycle events.
 *
 * Listeners receive events in registration order, sequentially. A listener
 * exception is not swallowed: it aborts the property run as an infrastructure
 * failure. A listener observes — it can never change a property's outcome.
 *
 * Event payloads may hold the very object instances the engine keeps using
 * (generated arguments, the counterexample): the identity is deliberate, so a
 * reporter can correlate what it saw with what the property reports. The other
 * side of that coin is a contract, not a defence — a listener must not mutate
 * objects reachable from a payload. Deep-detaching every value on the hot path
 * is not generally possible (closures, resources, identity-bearing DTOs) and
 * is not attempted.
 *
 * @api
 */
interface PropertyListener
{
    public function onEvent(PropertyEvent $event): void;
}
