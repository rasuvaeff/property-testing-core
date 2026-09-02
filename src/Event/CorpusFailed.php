<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Event;

/**
 * The corpus threw — a Redis server that refused the connection, a database
 * that could not be selected, a client error — and the property continues
 * without it: no recorded failure was replayed (when it happened on recall)
 * or none was stored (on remember/prune). The corpus is memory, not a
 * verdict, so its infrastructure failing must not fail the property; the
 * event is how a listener (the adapter's verbose trace) makes it visible.
 *
 * @api
 */
final readonly class CorpusFailed implements PropertyEvent
{
    /**
     * @param 'recall'|'remember'|'prune' $operation
     */
    public function __construct(
        public string $propertyId,
        public string $operation,
        public \Throwable $failure,
    ) {}
}
