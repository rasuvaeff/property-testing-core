<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * One line of a property's diagnostics, as {@see DiagnosticLines::of()}
 * words it.
 *
 * @api
 */
final readonly class DiagnosticLine
{
    /**
     * @param DiagnosticLevel $level Whether the line informs or warns.
     * @param non-empty-string $text The line, without a trailing newline.
     */
    public function __construct(
        public DiagnosticLevel $level,
        public string $text,
    ) {}
}
