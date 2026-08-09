<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

/**
 * Enum fixture for {@see \Rasuvaeff\PropertyTesting\Gen::enum()} tests: cases
 * are declared simplest-first, matching the shrink-toward-earlier contract.
 */
enum Priority
{
    case Low;
    case Medium;
    case High;
}
