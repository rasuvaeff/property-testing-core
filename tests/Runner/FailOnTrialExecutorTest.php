<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\FailOnTrialExecutor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(FailOnTrialExecutor::class)]
final class FailOnTrialExecutorTest
{
    public function aMatchingDiagnosticFailsTheRunWhereItWasRaised(): void
    {
        $line = 0;
        $executor = new FailOnTrialExecutor(new CallableTrialExecutor(static function (int $x) use (&$line): void {
            $line = __LINE__ + 1;
            trigger_error('diagnostic from ' . $x, E_USER_WARNING);
        }), E_USER_WARNING);

        $outcome = $executor->execute(['x' => 3]);

        Assert::true($outcome->isFailed());
        $failure = $outcome->failure;
        Assert::instanceOf($failure, \ErrorException::class);
        Assert::same($failure->getMessage(), 'diagnostic from 3');
        Assert::same($failure->getSeverity(), E_USER_WARNING);
        Assert::same($failure->getCode(), 0);
        Assert::same($failure->getFile(), __FILE__);
        Assert::same($failure->getLine(), $line);
    }

    public function theHandlerIsRestoredAfterEveryRun(): void
    {
        $before = set_error_handler(null);
        restore_error_handler();

        (new FailOnTrialExecutor(new CallableTrialExecutor(static function (): void {}), E_USER_WARNING))->execute([]);
        (new FailOnTrialExecutor(new CallableTrialExecutor(static function (): void {
            throw new \RuntimeException('body failed');
        }), E_USER_WARNING))->execute([]);

        $after = set_error_handler(null);
        restore_error_handler();

        Assert::same($after, $before);
    }

    public function aSuppressedDiagnosticStaysSuppressed(): void
    {
        $executor = new FailOnTrialExecutor(new CallableTrialExecutor(static function (): void {
            @trigger_error('silenced', E_USER_WARNING);
        }), E_USER_WARNING);

        Assert::true($executor->execute([])->isPassed());
    }

    public function aDiagnosticItDoesNotClaimGoesToThePreviousHandler(): void
    {
        $seen = [];
        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = [$severity, $message];

            return true;
        });

        try {
            $outcome = (new FailOnTrialExecutor(new CallableTrialExecutor(static function (): void {
                trigger_error('only a notice', E_USER_NOTICE);
            }), E_USER_WARNING))->execute([]);
        } finally {
            restore_error_handler();
        }

        Assert::true($outcome->isPassed());
        Assert::same($seen, [[E_USER_NOTICE, 'only a notice']]);
    }

    public function withoutAPreviousHandlerAnUnclaimedDiagnosticGoesToPhp(): void
    {
        set_error_handler(null);
        error_clear_last();

        try {
            $outcome = (new FailOnTrialExecutor(new CallableTrialExecutor(static function (): void {
                // Silenced: PHP's own handling must not print into the suite.
                @trigger_error('left to php', E_USER_NOTICE);
            }), E_USER_WARNING))->execute([]);
        } finally {
            restore_error_handler();
        }

        Assert::true($outcome->isPassed());
        // PHP's own handling ran: the handler declined the diagnostic.
        Assert::same(error_get_last()['message'] ?? null, 'left to php');
    }

    /**
     * Whatever the previous handler answers is passed on as a bool.
     */
    public function thePreviousHandlersAnswerIsPassedOnAsABool(): void
    {
        set_error_handler(static fn(): int => 1);

        try {
            $outcome = (new FailOnTrialExecutor(new CallableTrialExecutor(static function (): void {
                trigger_error('handled by the previous one', E_USER_NOTICE);
            }), E_USER_WARNING))->execute([]);
        } finally {
            restore_error_handler();
        }

        Assert::true($outcome->isPassed());
    }

    /**
     * An explicit level sees what the suite masks.
     */
    public function anExplicitLevelSeesAMaskedDiagnostic(): void
    {
        $reporting = error_reporting(E_ALL & ~E_USER_DEPRECATED);

        try {
            $outcome = (new FailOnTrialExecutor(new CallableTrialExecutor(static function (): void {
                trigger_error('deprecated', E_USER_DEPRECATED);
            }), E_USER_DEPRECATED))->execute([]);
        } finally {
            error_reporting($reporting);
        }

        Assert::true($outcome->isFailed());
    }

    public function noLevelsChangesNothing(): void
    {
        $seen = 0;
        set_error_handler(static function () use (&$seen): bool {
            ++$seen;

            return true;
        });

        try {
            $outcome = (new FailOnTrialExecutor(new CallableTrialExecutor(static function (): void {
                trigger_error('warning', E_USER_WARNING);
            }), 0))->execute([]);
        } finally {
            restore_error_handler();
        }

        Assert::true($outcome->isPassed());
        Assert::same($seen, 1);
    }

    public function noLevelsInstallsNoHandler(): void
    {
        $outer = static fn(): bool => true;
        set_error_handler($outer);

        try {
            $seen = FailOnTrialExecutor::guard(0, static function (): mixed {
                $current = set_error_handler(null);
                restore_error_handler();

                return $current;
            });
        } finally {
            restore_error_handler();
        }

        Assert::same($seen, $outer);
    }

    public function guardReturnsWhatTheCallReturns(): void
    {
        Assert::same(FailOnTrialExecutor::guard(E_USER_WARNING, static fn(): int => 42), 42);
        Assert::same(FailOnTrialExecutor::guard(0, static fn(): string => 'unguarded'), 'unguarded');
    }

    public function aNegativeMaskIsRefused(): void
    {
        try {
            new FailOnTrialExecutor(new CallableTrialExecutor(static function (): void {}), -1);

            Assert::fail('expected the mask to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Fail-on levels must be greater than or equal to 0, got -1');
        }
    }
}
