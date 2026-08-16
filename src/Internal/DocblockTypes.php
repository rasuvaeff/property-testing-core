<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

/**
 * The `@param` types a function's docblock declares, by parameter name.
 *
 * Deliberately a small reader rather than a docblock parser: it takes the type
 * expression as written and hands it to {@see TypeGenerators}, which is the
 * only thing that decides what a type means. Any function reflection works —
 * a constructor is where {@see \Rasuvaeff\PropertyTesting\Arbitrary\ClassArbitrary}
 * reads promoted properties from (that is where this family writes them), and
 * a property method or closure is what
 * {@see \Rasuvaeff\PropertyTesting\Gen::forParameters()} reads.
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
     * @param \ReflectionFunctionAbstract $function The function to read.
     *
     * @return array<string, string> Type expression by parameter name; missing for undocumented ones.
     */
    public static function of(\ReflectionFunctionAbstract $function): array
    {
        $docblock = $function->getDocComment();

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
