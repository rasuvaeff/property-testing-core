<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

/**
 * The textual form of a shrink descent: the accepted steps of one
 * minimisation, as `name:index` segments joined by `/`.
 *
 * A step names what was replaced (a parameter, or an in-body draw under its
 * `draw#N` pseudo-name) and which candidate of that node's
 * {@see \Rasuvaeff\PropertyTesting\Shrinkable::shrinks()} enumeration was
 * accepted. The index counts every candidate the enumeration yields, including
 * ones the descent skipped, so a recorded step means the same thing to the
 * replay that reads it back.
 *
 * @internal
 *
 * @psalm-type Step = array{name: string, index: int<0, max>}
 */
final class ShrinkPath
{
    /**
     * A parameter name is a PHP identifier; an in-body draw is reported under
     * `draw#N`, which cannot collide with one. Indices are bounded at nine
     * digits: a candidate a billion deep into a shrink enumeration is a typo,
     * not a path, and the bound keeps the parse away from integer saturation.
     */
    private const string SEGMENT = '(?:[A-Za-z_]\w*|draw#[1-9]\d{0,8}):\d{1,9}';

    /**
     * The recorded steps of a path.
     *
     * @return list<Step>
     *
     * @throws \InvalidArgumentException when the path is not a sequence of
     *   `name:index` segments — a malformed path is a typo in something the
     *   developer pasted, and silently ignoring it would report a fresh search
     *   as a replay.
     */
    public static function parse(string $path): array
    {
        // `~` delimits, because both `#` (draw names) and `/` (the separator)
        // occur in the pattern itself.
        if (preg_match('~^' . self::SEGMENT . '(?:/' . self::SEGMENT . ')*\z~', $path) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid shrink path "%s"', $path));
        }

        return array_map(
            static function (string $segment): array {
                // The pattern above guarantees exactly one `:`, so no limit is
                // needed; the index is read through end() because a non-empty
                // list is all static analysis can infer from explode() alone.
                $parts = explode(':', $segment);

                /** @var int<0, max> $index */
                $index = (int) end($parts);

                return ['name' => $parts[0], 'index' => $index];
            },
            explode('/', $path),
        );
    }

    /**
     * The textual form of recorded steps; the empty string when nothing was
     * accepted, which is what a descent that found no improvement (or never
     * ran) reports.
     *
     * @param list<Step> $steps
     */
    public static function format(array $steps): string
    {
        return implode('/', array_map(
            static fn(array $step): string => $step['name'] . ':' . $step['index'],
            $steps,
        ));
    }

    /**
     * The one-based draw a `draw#N` step refers to, or null when the step names
     * a parameter. One-based because that is how a draw is reported; the caller
     * turns it into a tape position.
     */
    public static function drawNumber(string $name): ?int
    {
        if (!str_starts_with($name, 'draw#')) {
            return null;
        }

        return (int) substr($name, 5);
    }
}
