<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\Event\RunDiscarded;
use Rasuvaeff\PropertyTesting\Event\RunFailed;
use Rasuvaeff\PropertyTesting\Event\RunPassed;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\Event\TargetImproved;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\ValueRenderer;

/**
 * The `PROPERTY_VERBOSE` trace as a listener, worded once for every adapter:
 * one line per attempt's arguments, one per attempt's in-body draws, one per
 * target improvement, one per accepted shrink step. Each line goes to the
 * sink the adapter hands over — a stream, a logger — without a trailing
 * newline.
 *
 * ```php
 * $listeners[] = new TraceListener(static function (string $line): void {
 *     fwrite(STDOUT, $line . "\n");
 * });
 * ```
 *
 * Exception-hardened by policy: a listener exception normally aborts the run
 * as an infrastructure failure, but a bug in a built-in trace — or in the sink
 * it writes to — must not fail every verbose consumer, so it swallows its own
 * errors instead.
 *
 * @api
 */
final readonly class TraceListener implements PropertyListener
{
    /**
     * @param \Closure(non-empty-string): void $sink Receives each line.
     */
    public function __construct(
        private \Closure $sink,
    ) {}

    /**
     * Writes the line for an event the trace covers; ignores the others.
     *
     * @param PropertyEvent $event Any engine event.
     */
    #[\Override]
    public function onEvent(PropertyEvent $event): void
    {
        try {
            if ($event instanceof RunStarted) {
                $this->write(sprintf(
                    'Property "%s" attempt %d: %s',
                    $this->name($event->propertyId),
                    $event->attempt,
                    ValueRenderer::renderArguments($event->arguments),
                ));
            } elseif ($event instanceof RunPassed || $event instanceof RunFailed || $event instanceof RunDiscarded) {
                if ($event->draws !== []) {
                    $this->write(sprintf(
                        'Property "%s" attempt %d draws: %s',
                        $this->name($event->propertyId),
                        $event->attempt,
                        ValueRenderer::renderArguments($event->draws),
                    ));
                }
            } elseif ($event instanceof TargetImproved) {
                $this->write(sprintf(
                    'Property "%s" target %s %s: %s -> %s (%s)',
                    $this->name($event->propertyId),
                    $event->label,
                    $event->direction === TargetDirection::Maximize ? 'max' : 'min',
                    $event->previous === null ? '-' : ValueRenderer::render($event->previous),
                    ValueRenderer::render($event->score),
                    ValueRenderer::renderArguments($event->arguments),
                ));
            } elseif ($event instanceof ShrinkAccepted) {
                $this->write(sprintf(
                    'Property "%s" shrink step %d: %s=%s -> %s',
                    $this->name($event->propertyId),
                    $event->step,
                    $event->parameter,
                    ValueRenderer::render($event->before),
                    ValueRenderer::render($event->after),
                ));
            }
        } catch (\Throwable) {
            // Deliberately swallowed — see the class docblock.
        }
    }

    /**
     * The display name the messages use: the method part of a
     * `Class::method` property id.
     */
    private function name(string $propertyId): string
    {
        $separator = strrpos($propertyId, '::');

        return $separator === false ? $propertyId : substr($propertyId, $separator + 2);
    }

    /**
     * @param non-empty-string $line
     */
    private function write(string $line): void
    {
        ($this->sink)($line);
    }
}
