<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * What a property's id has to be good for, and how to tell when it is not.
 *
 * The id keys two things that outlive a single run: the events a listener
 * aggregates, and the regression corpus entry that replays yesterday's
 * counterexample. Both need it to name the same property tomorrow. An adapter
 * that derives the id from a backtrace gets that for a test method and loses
 * it for a closure, because PHP has never had a stable name for one:
 *
 * ```text
 * PHP 8.3   Suite::{closure}
 * PHP 8.4+  Suite::{closure:/app/tests/StackTest.php:19}
 * ```
 *
 * Neither is usable. On 8.3 every closure of a class collapses onto one id, so
 * two properties in one file overwrite each other's recorded counterexample;
 * from 8.4 the id carries a file and a line, so inserting a line above the
 * property orphans the entry it recorded yesterday. The corpus keeps working
 * in the sense that nothing throws — it simply stops replaying the failure it
 * was built to replay, which is the failure mode a corpus exists to prevent.
 *
 * So this is a diagnosis, not a fix: the engine returns the sentence, and the
 * adapter prints it through whatever channel it already warns on. The engine
 * itself never prints — see the README's account of what it does not do.
 *
 * @api
 */
final class PropertyId
{
    /**
     * The marker of a closure-derived id, matched without its closing brace so
     * both PHP's spellings are caught: `{closure}` and `{closure:file:line}`.
     * The newer form is the one that matters most — it looks specific enough
     * to trust, and it moves the moment anyone edits the lines above.
     */
    private const string CLOSURE_MARKER = '{closure';

    private function __construct()
    {
        // Static facade; not instantiable.
    }

    /**
     * The warning an adapter should show for $id, or null when the id is
     * stable and there is nothing to say.
     *
     * @param string $id The property id as the adapter derived it.
     */
    public static function unstableWarning(string $id): ?string
    {
        if (!str_contains($id, self::CLOSURE_MARKER)) {
            return null;
        }

        return sprintf(
            'Property id "%s" comes from a closure and is not stable: PHP 8.3 gives every closure of a class '
            . 'the same name, and from 8.4 the name carries a line number that any edit above shifts. '
            . 'The regression corpus is keyed by it — pass an explicit property id',
            $id,
        );
    }
}
