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

    /**
     * The class names a docblock in $function's file can use unqualified,
     * as the declaring namespace and the `use` aliases in effect there.
     *
     * A docblock names classes the way the source does — `LineItem`, not
     * `\App\Order\LineItem` — and reflection reports no import table, so the
     * file is read once for it. Only top-level `namespace` and `use` are
     * honoured (the forms the family writes); a function outside any file
     * (an `eval`'d closure) has neither.
     *
     * @return array{namespace: string, aliases: array<string, string>} The namespace without
     *         leading or trailing backslash ('' for global), and the imported classes by the
     *         name they are known as (the alias or the last segment), lower-cased.
     */
    public static function imports(\ReflectionFunctionAbstract $function): array
    {
        $file = $function->getFileName();

        if ($file === false) {
            return ['namespace' => '', 'aliases' => []];
        }

        /** @var array<string, array{namespace: string, aliases: array<string, string>}> $cache */
        static $cache = [];

        return $cache[$file] ??= self::importsOf($file);
    }

    /**
     * @return array{namespace: string, aliases: array<string, string>}
     */
    private static function importsOf(string $file): array
    {
        $source = @file_get_contents($file);

        if ($source === false) {
            return ['namespace' => '', 'aliases' => []];
        }

        $namespace = '';
        $aliases = [];
        $depth = 0;

        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->text === '{') {
                ++$depth;
            } elseif ($token->text === '}') {
                --$depth;
            }

            if ($depth > 0 || !$token->is([T_NAMESPACE, T_USE])) {
                continue;
            }

            // The statement is the text up to its terminator: `;` for a
            // `use` (whose group form carries braces of its own), `;` or the
            // opening brace for a `namespace`. The tokenizer already handed
            // out the tokens after this one, so read the source directly.
            $statement = substr($source, $token->pos, strcspn($source, $token->is(T_USE) ? ';' : ';{', $token->pos));

            if ($token->is(T_NAMESPACE)) {
                $namespace = trim(substr($statement, strlen('namespace')), " \t\n\r\\");

                continue;
            }

            self::collectAliases($statement, $aliases);
        }

        return ['namespace' => $namespace, 'aliases' => $aliases];
    }

    /**
     * `use A\B;`, `use A\B as C;`, `use A\{B, C as D};` — function and const
     * imports are skipped, they never name a class.
     *
     * @param array<string, string> $aliases
     */
    private static function collectAliases(string $statement, array &$aliases): void
    {
        $body = trim(substr($statement, strlen('use')));

        if (preg_match('/^(function|const)\s/i', $body) === 1) {
            return;
        }

        $prefix = '';

        if (preg_match('/^(?<prefix>[^{]*)\\\{(?<group>[^}]*)\}\z/s', $body, $matches) === 1) {
            $prefix = trim($matches['prefix'], " \t\n\r\\") . '\\';
            $body = $matches['group'];
        }

        foreach (explode(',', $body) as $import) {
            $import = trim($import);

            if ($import === '' || preg_match('/^(function|const)\s/i', $import) === 1) {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $import) ?: [$import];
            $class = $prefix . ltrim(trim($parts[0]), '\\');
            $alias = isset($parts[1]) ? trim($parts[1]) : (string) strrchr('\\' . $class, '\\');
            $aliases[strtolower(ltrim($alias, '\\'))] = $class;
        }
    }
}
