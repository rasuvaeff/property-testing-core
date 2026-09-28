<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

/**
 * What makes two failures of one property the same failure: the exception
 * class, and the place in the property's own file that raised it.
 *
 * The class alone is not enough. A body that can fail in more than one place —
 * two assertions, or an assertion next to a call that throws the same type —
 * offers the shrink descent several failures that look identical, so the
 * descent may leave the failure it was given and minimise a different one. The
 * reported counterexample then belongs to a bug the run never found, and
 * nothing in the output says so.
 *
 * The place is read from the property's own file rather than from the
 * throwable's `getFile()`/`getLine()` alone, because an assertion library
 * throws from one line whatever the assertion was: PHPUnit raises every
 * constraint failure from `Constraint::fail()`, Testo every comparison from
 * `Assert`. Comparing those would separate an assertion failure from a
 * `TypeError` — which the class already does — and collapse two assertions
 * into one. The frame in the property's file is the assertion the body wrote.
 *
 * Walking to "the first frame outside the engine" instead does not work: under
 * Testo the body is reached through the framework's interceptor pipeline, so
 * the frames next to the engine belong to Testo, not to the property.
 *
 * Both sides fall back to the class alone whenever the place is unknown — an
 * id that names no loaded class (an adapter-supplied string, a closure-derived
 * id), a body defined in another file (a trait, an included fixture), a
 * failure raised by the engine rather than by the body. A criterion that
 * guessed instead would reject candidates that fail the way the original did,
 * and a descent that rejects everything stalls on the input it started from —
 * the same silence, at a larger counterexample.
 *
 * @internal
 */
final readonly class FailureIdentity
{
    private function __construct(
        private ?string $class,
        private ?string $file,
        private string $boundary,
        private ?int $site,
    ) {}

    /**
     * @param string $propertyId The property's id; its class part, when it names a loaded
     *        class, locates the file the property is written in.
     * @param ?\Throwable $failure What the original run failed with. Null — or a candidate
     *        that fails without a throwable — leaves nothing to compare, and any failure
     *        counts as the same one.
     * @param string $boundary The engine's own file. Frames from it outwards are the engine
     *        calling the body, not the body: without that bound, an adapter that invokes the
     *        body from the test file itself (PHPUnit's `check()` call) would report that call
     *        as the site of every failure raised outside the body, while an adapter that
     *        invokes it through reflection (Testo) would report none — the same property would
     *        shrink differently under the two.
     */
    public static function of(string $propertyId, ?\Throwable $failure, string $boundary): self
    {
        $file = self::propertyFile($propertyId);

        return new self(
            class: $failure instanceof \Throwable ? $failure::class : null,
            file: $file,
            boundary: $boundary,
            site: $failure instanceof \Throwable ? self::site($failure, $file, $boundary) : null,
        );
    }

    /**
     * Whether $candidate is the failure this identity was built from.
     */
    public function matches(?\Throwable $candidate): bool
    {
        if ($this->class === null || !$candidate instanceof \Throwable) {
            return true;
        }

        if ($candidate::class !== $this->class) {
            return false;
        }

        if ($this->site === null) {
            return true;
        }

        $site = self::site($candidate, $this->file, $this->boundary);

        return $site === null || $site === $this->site;
    }

    /**
     * Which line of $file raised the failure: the throwable's own line when the
     * body threw it, otherwise the line of the innermost frame that is still in
     * the body — an assertion helper is entered from the line the body wrote.
     *
     * A line is enough to tell two places apart because the file is fixed for
     * the whole identity: the frame scan only ever matches $file, and a
     * throwable from anywhere else has no place in the property at all.
     */
    private static function site(\Throwable $failure, ?string $file, string $boundary): ?int
    {
        // An unknown file needs no guard of its own: it is neither the file the
        // throwable names nor any frame's, so the search below finds nothing.
        if ($failure->getFile() === $file) {
            return $failure->getLine();
        }

        foreach ($failure->getTrace() as $frame) {
            if (!isset($frame['file'], $frame['line'])) {
                // An internal call — a property method reached through
                // `ReflectionMethod::invoke()` — has no place of its own.
                continue;
            }

            if ($frame['file'] === $boundary) {
                return null;
            }

            if ($frame['file'] === $file) {
                return $frame['line'];
            }
        }

        return null;
    }

    /**
     * The file of the class an id names, or null when the id does not name a
     * loaded one. Loading is deliberately not attempted: an id is an arbitrary
     * string an adapter chose, and autoloading it would put every failing
     * property's id through the autoloader chain — a class map lookup at best,
     * an autoloader with side effects at worst. A property that runs has its
     * class loaded already; anything else is not a class.
     */
    private static function propertyFile(string $propertyId): ?string
    {
        $separator = strpos($propertyId, '::');
        $class = $separator === false ? $propertyId : substr($propertyId, 0, $separator);

        if (!class_exists($class, autoload: false)) {
            return null;
        }

        $file = (new \ReflectionClass($class))->getFileName();

        return $file === false ? null : $file;
    }
}
