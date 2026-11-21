<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * How a {@see DiagnosticLine} reads: information a developer may want while
 * debugging, or a warning that the run checked less than it looks like.
 * Adapters map it onto their own channels — the PHPUnit adapter prints info on
 * stdout and warnings on stderr, the Testo adapter logs both on its stderr
 * channel at the matching log level.
 *
 * @api
 */
enum DiagnosticLevel
{
    case Info;
    case Warning;
}
