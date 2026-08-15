<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

/**
 * The `@param` types a constructor's docblock declares, by parameter name.
 *
 * Deliberately a small reader rather than a docblock parser: it takes the type
 * expression as written and hands it to {@see TypeGenerators}, which is the
 * only thing that decides what a type means. Promoted properties are read from
 * the constructor docblock too, because that is where this family writes them.
 *
 * @internal
 */
final class DocblockTypes
{
    private function __construct()
    {
        // Static helpers; not instantiable.
    }

    /**
     * @param \ReflectionMethod $constructor The constructor to read.
     *
     * @return array<string, string> Type expression by parameter name; missing for undocumented ones.
     */
    public static function of(\ReflectionMethod $constructor): array
    {
        $docblock = $constructor->getDocComment();

        if ($docblock === false) {
            return [];
        }

        // `@param <type> $name` — the type is everything between the tag and
        // the variable, which is what keeps `array<string, int>` in one piece.
        if (preg_match_all('/@param\s+(?<type>.+?)\s+\$(?<name>[A-Za-z_][A-Za-z0-9_]*)/', $docblock, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $types = [];

        foreach ($matches as $match) {
            $types[$match['name']] = trim($match['type']);
        }

        return $types;
    }
}
