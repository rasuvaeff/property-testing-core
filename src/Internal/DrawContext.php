<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Process-local channel between {@see \Rasuvaeff\PropertyTesting\Gen::draw()}
 * calls in a property body and the property runner — the replay tape that makes in-body draws
 * shrinkable — and the notes {@see \Rasuvaeff\PropertyTesting\Gen::note()} attaches to a run.
 *
 * During a normal run the tape is empty: every draw generates a fresh
 * {@see Shrinkable} from the run's {@see Random} and records it. During a
 * shrink trial the runner replays a (possibly modified) tape: draws are served
 * from it by position, and only draws past its end — a longer draw sequence
 * caused by changed control flow — generate anew. The runner collects what was
 * actually used via {@see disarm()} and adopts it as the next tape when the
 * trial still fails.
 *
 * Like {@see \Rasuvaeff\PropertyTesting\Classify}, this is a mutable static —
 * an accepted exception to the "only Random is mutable" invariant. Property
 * runs are sequential, so the state is never shared concurrently.
 *
 * @internal Driven by the property runner.
 */
final class DrawContext
{
    private static ?Random $random = null;

    /**
     * Tape replayed by position during shrink trials (empty on a normal run).
     *
     * @var list<Shrinkable>
     */
    private static array $tape = [];

    private static int $position = 0;

    /**
     * Nodes actually served to the body during the current run.
     *
     * @var list<Shrinkable>
     */
    private static array $recorded = [];

    /**
     * Values the body attached to the current run, by label; a later note
     * under the same label replaces the earlier one.
     *
     * @var array<string, mixed>
     */
    private static array $notes = [];

    /**
     * Prepare the context for one execution of the property body.
     *
     * @param list<Shrinkable> $tape
     */
    public static function arm(Random $random, array $tape = []): void
    {
        self::$random = $random;
        self::$tape = $tape;
        self::$position = 0;
        self::$recorded = [];
        self::$notes = [];
    }

    /**
     * Attach a labelled value to the current run. Kept until {@see disarm()};
     * the runner reads it through {@see notes()} for the run it reports.
     */
    public static function note(string $label, mixed $value): void
    {
        if (!self::$random instanceof Random) {
            throw new \RuntimeException('Gen::note() may only be called inside a property run');
        }

        // A replace rather than an element write: the merge keeps the array's
        // declared type where an element assignment of `mixed` would not.
        self::$notes = array_replace(self::$notes, [$label => $value]);
    }

    /**
     * The notes of the current run, in the order first attached.
     *
     * @return array<string, mixed>
     */
    public static function notes(): array
    {
        return self::$notes;
    }

    /**
     * Serve one draw: replay the tape while it lasts, generate past its end.
     * A replayed node is served as-is — it is not re-validated against
     * $arbitrary, which may differ from the one that generated it when an
     * earlier shrink changed the body's control flow.
     */
    public static function draw(ArbitraryInterface $arbitrary): mixed
    {
        $random = self::$random;

        if (!$random instanceof Random) {
            throw new \RuntimeException('Gen::draw() may only be called inside a property run');
        }

        $node = self::$position < count(self::$tape)
            ? self::$tape[self::$position]
            : $arbitrary->generate($random);

        self::$recorded[] = $node;
        ++self::$position;

        return $node->value;
    }

    /**
     * Return the nodes served during the run and reset the context.
     *
     * @return list<Shrinkable>
     */
    public static function disarm(): array
    {
        $recorded = self::$recorded;
        self::$random = null;
        self::$tape = [];
        self::$position = 0;
        self::$recorded = [];
        self::$notes = [];

        return $recorded;
    }
}
