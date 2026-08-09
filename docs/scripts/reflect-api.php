<?php

declare(strict_types=1);

// Multi-root API reflection for the property-testing family site
// (property-testing-evolution-plan.md, §I.2).
//
// Three source trees share ONE PSR-4 prefix (Rasuvaeff\PropertyTesting\ ->
// src/), by design (drop-in migration, see the family AGENTS.md). Core is
// reflected from the LIVE working tree of this checkout — the site always
// documents the branch it is built from. The adapters are reflected from
// docs/.api-workspace/vendor/, which installs them from their published
// Packagist tags: a method added on an adapter's master will not appear
// here until that adapter releases. This is deliberate (plan §I.2) and the
// price is that a release must trigger a site rebuild, or the reference
// silently lags — see the "why" note in the evolution plan before changing
// which tree an adapter root points at.
//
// docs/.api-workspace exists SOLELY so this script can autoload all three
// src/ trees through one vendor/autoload.php without core depending on its
// own adapters (that would break the Stage F exit criterion: `composer why
// testo/testo` must stay empty for a core-only install). Run via:
//   docker run --rm -v "<monorepo-root>":/repo -w /repo/property-testing-core/docs/.api-workspace composer:2 composer install
//   docker run --rm -v "<monorepo-root>":/repo -w /repo composer:2 php property-testing-core/docs/scripts/reflect-api.php > property-testing-core/docs/scripts/api-snapshot.json
// The path repository for core MUST see the monorepo root mounted at the
// same relative depth as on disk — mounting only docs/.api-workspace makes
// "../.." resolve to nowhere inside the container and composer silently
// falls back to installing core from Packagist instead of this checkout.
// That failure is easy to miss: it still "succeeds", just reflects a stale
// release. Verified live 2026-08-09 with a canary line in src/Gen.php.

// dirname(), not '__DIR__ . "/../.api-workspace"': the ownership check below
// compares this string against ReflectionClass::getFileName(), which PHP
// always normalizes — a literal ".." segment here would make an otherwise
// identical path compare unequal and silently drop every testo/phpunit
// class from the report (found live 2026-08-09: 84 core entries, 0 for
// either adapter, no error).
$workspaceDir = dirname(__DIR__) . '/.api-workspace';
$workspaceAutoload = $workspaceDir . '/vendor/autoload.php';

if (!is_file($workspaceAutoload)) {
    fwrite(STDERR, "Missing $workspaceAutoload — run `composer install` in docs/.api-workspace first.\n");
    exit(1);
}

require $workspaceAutoload;

use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Tags\Deprecated;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\See;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use phpDocumentor\Reflection\Types\ContextFactory;

/** @return array{version: string|null, reference: string|null} */
function installedPackageMeta(string $workspaceDir, string $packageName): array
{
    $installedPath = $workspaceDir . '/vendor/composer/installed.json';
    if (!is_file($installedPath)) {
        return ['version' => null, 'reference' => null];
    }

    $installed = json_decode(file_get_contents($installedPath), true, flags: JSON_THROW_ON_ERROR);
    $packages = $installed['packages'] ?? $installed; // composer v1 had no 'packages' wrapper

    foreach ($packages as $package) {
        if (($package['name'] ?? null) === $packageName) {
            return [
                'version' => $package['version'] ?? null,
                'reference' => $package['source']['reference'] ?? $package['dist']['reference'] ?? null,
            ];
        }
    }

    return ['version' => null, 'reference' => null];
}

$coreDir = dirname(__DIR__, 2);

// The adapters carry a real version because they are installed from Packagist
// (composer/installed.json). Core is reflected from this checkout, which has
// no such record — and "working tree" is the honest answer only while someone
// is running this locally. On a deployed page it is the one package the
// reader cannot place in time, on the pages where that matters most, so CI
// passes the version it is building: DOCS_CORE_VERSION, from `git describe`.
// See docs.yml and issue #9.
$coreVersion = ['version' => getenv('DOCS_CORE_VERSION') ?: 'working tree', 'reference' => null];
$testoVersion = installedPackageMeta($workspaceDir, 'rasuvaeff/property-testing-testo');
$phpunitVersion = installedPackageMeta($workspaceDir, 'rasuvaeff/property-testing-phpunit');

/**
 * @return list<array{label: string, srcDir: string, nsPrefix: string, repoBlob: string, version: string|null, reference: string|null}>
 */
function roots(string $coreDir, string $workspaceDir, array $coreVersion, array $testoVersion, array $phpunitVersion): array
{
    $ns = 'Rasuvaeff\\PropertyTesting\\';

    return [
        [
            'label' => 'core',
            'srcDir' => $coreDir . '/src',
            'nsPrefix' => $ns,
            'repoBlob' => 'https://github.com/rasuvaeff/property-testing-core/blob/master/src/',
            'version' => $coreVersion['version'],
            'reference' => $coreVersion['reference'],
        ],
        [
            'label' => 'testo',
            'srcDir' => $workspaceDir . '/vendor/rasuvaeff/property-testing-testo/src',
            'nsPrefix' => $ns,
            // The commit SHA, not the version string: composer's installed.json
            // reports it as "v0.1.0" (the raw git tag, already "v"-prefixed) —
            // string-prepending another "v" here produced a dead "vv0.1.0" blob
            // link, found live 2026-08-09. The reference is unambiguous either way.
            'repoBlob' => 'https://github.com/rasuvaeff/property-testing-testo/blob/' . ($testoVersion['reference'] ?? 'master') . '/src/',
            'version' => $testoVersion['version'],
            'reference' => $testoVersion['reference'],
        ],
        [
            'label' => 'phpunit',
            'srcDir' => $workspaceDir . '/vendor/rasuvaeff/property-testing-phpunit/src',
            'nsPrefix' => $ns,
            'repoBlob' => 'https://github.com/rasuvaeff/property-testing-phpunit/blob/' . ($phpunitVersion['reference'] ?? 'master') . '/src/',
            'version' => $phpunitVersion['version'],
            'reference' => $phpunitVersion['reference'],
        ],
    ];
}

/** @return list<string> */
function findPhpFiles(string $dir): array
{
    $files = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $files = [...$files, ...findPhpFiles($path)];
        } elseif (str_ends_with($entry, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

function classNameFromPath(string $srcDir, string $nsPrefix, string $path): string
{
    $relative = substr($path, strlen($srcDir) + 1, -4); // strip src/ prefix and .php suffix
    $relative = str_replace('/', '\\', $relative);

    return $nsPrefix . $relative;
}

function stripInlineTags(string $text): string
{
    // phpDocumentor's Description::render() does NOT expand inline
    // {@see X}/{@link X} markers into anything — it reproduces the tag
    // syntax verbatim in the rendered text (found live 2026-08-09: prose
    // came out as literal "{@see \Rasuvaeff\PropertyTesting\Shrinkable}").
    // Stripped down to the bare reference, a resolved `\Fully\Qualified\Name`
    // is exactly what generate-api.mjs's linkType() already knows how to
    // turn into a page link — no second resolution step needed on the JS side.
    return preg_replace('/\{@(?:see|link)\s+([^}]+)\}/', '$1', $text) ?? $text;
}

function tagBody(Tag $tag): string
{
    // Every DocBlock tag renders its own body via __toString() — the `Tag`
    // interface all of them implement, known and Generic/unknown alike
    // (including a malformed one, InvalidTag) — so this is how
    // @template/@implements/@api/@psalm-* extension tags, none of which
    // phpDocumentor has a typed class for, get captured without special-casing.
    return trim((string) $tag);
}

/**
 * @return array{
 *     summary: string,
 *     description: string,
 *     params: array<string, array{type: string, description: string}>,
 *     throws: list<array{type: string, description: string}>,
 *     see: list<string>,
 *     deprecated: string|null,
 *     isApi: bool,
 *     extensionTags: array<string, list<string>>,
 * }
 */
function parseDocBlock(
    DocBlockFactory $factory,
    ContextFactory $contextFactory,
    ReflectionClass|ReflectionMethod|ReflectionClassConstant $reflector,
): array {
    $empty = [
        'summary' => '',
        'description' => '',
        'params' => [],
        'throws' => [],
        'see' => [],
        'deprecated' => null,
        'isApi' => false,
        'extensionTags' => [],
    ];

    $docComment = $reflector->getDocComment();
    if ($docComment === false || trim($docComment) === '') {
        return $empty;
    }

    try {
        // Without a Context, phpDocumentor resolves every short type name
        // (ArbitraryInterface, TValue, ...) against the GLOBAL namespace, so
        // `@param ArbitraryInterface<TValue> $arbitrary` renders as
        // `\ArbitraryInterface<\TValue>` instead of the real FQCN — found
        // live 2026-08-09 on Gen::draw()'s @param. createFromReflector()
        // reads the declaring class's file and its `use` statements to
        // resolve short names the way PHP itself would.
        $context = $contextFactory->createFromReflector($reflector);
        $block = $factory->create($docComment, $context);
    } catch (\Throwable) {
        // A malformed docblock (rare, e.g. an unbalanced inline tag) or a
        // context-building failure must not take the whole reflection run
        // down — report it as undocumented rather than crash;
        // check-integrity.mjs's completeness gate (I.5.6) will flag the
        // empty summary.
        return $empty;
    }

    $params = [];
    $throws = [];
    $see = [];
    $deprecated = null;
    $isApi = false;
    $extensionTags = [];

    foreach ($block->getTags() as $tag) {
        $name = $tag->getName();

        if ($tag instanceof Param) {
            $params[$tag->getVariableName() ?? ''] = [
                'type' => $tag->getType() !== null ? (string) $tag->getType() : '',
                'description' => $tag->getDescription() !== null ? stripInlineTags(trim($tag->getDescription()->render())) : '',
            ];

            continue;
        }

        if ($tag instanceof Throws) {
            $throws[] = [
                'type' => $tag->getType() !== null ? (string) $tag->getType() : '',
                'description' => $tag->getDescription() !== null ? stripInlineTags(trim($tag->getDescription()->render())) : '',
            ];

            continue;
        }

        if ($tag instanceof See) {
            $see[] = (string) $tag->getReference();

            continue;
        }

        if ($tag instanceof Deprecated) {
            $deprecated = stripInlineTags(trim((string) $tag->getVersion() . ' ' . ($tag->getDescription()?->render() ?? '')));

            continue;
        }

        if ($name === 'api') {
            $isApi = true;

            continue;
        }

        // Everything else — @template, @implements, @psalm-*, @since,
        // @internal, ... — kept verbatim, grouped by tag name, so the page
        // generator can render what it recognises and dump the rest as-is
        // instead of silently discarding it.
        $extensionTags[$name][] = tagBody($tag);
    }

    return [
        'summary' => stripInlineTags(trim($block->getSummary())),
        'description' => stripInlineTags(trim($block->getDescription()->render())),
        'params' => $params,
        'throws' => $throws,
        'see' => $see,
        'deprecated' => $deprecated,
        'isApi' => $isApi,
        'extensionTags' => $extensionTags,
    ];
}

function typeToString(?ReflectionType $type): ?string
{
    return $type?->__toString();
}

/**
 * The interface/parent declaration a method implements, or null when it
 * declares itself. `getPrototype()` throws instead of returning null when
 * there is none.
 */
function prototypeOf(ReflectionMethod $method): ?ReflectionMethod
{
    try {
        return $method->getPrototype();
    } catch (ReflectionException) {
        return null;
    }
}

/**
 * Fills a `#[Override]` implementation's empty documentation from the
 * declaration it implements, per-field.
 *
 * This codebase documents the contract on the interface and leaves the
 * implementations bare — twenty `Arbitrary\*::generate()` methods carry no
 * docblock at all because `ArbitraryInterface::generate()` says everything.
 * Without inheritance those pages render a bare signature, and the
 * completeness gate reports twenty findings whose only honest fix is
 * copy-pasting one docblock twenty times.
 *
 * Per-field, not all-or-nothing: an implementation that adds its own summary
 * but no `@param` descriptions keeps its summary and inherits the parameters.
 *
 * @param array<string, mixed> $own
 * @param array<string, mixed> $inherited
 * @return array<string, mixed>
 */
function inheritDoc(array $own, array $inherited): array
{
    if ($own['summary'] === '') {
        $own['summary'] = $inherited['summary'];
    }
    if ($own['description'] === '') {
        $own['description'] = $inherited['description'];
    }
    if ($own['throws'] === []) {
        $own['throws'] = $inherited['throws'];
    }

    foreach ($inherited['params'] as $name => $param) {
        if (($own['params'][$name]['description'] ?? '') === '') {
            $own['params'][$name] = [
                'type' => $own['params'][$name]['type'] ?? $param['type'],
                'description' => $param['description'],
            ];
        }
    }

    return $own;
}

/**
 * Whether the method's own source contains a `throw` — the input to the
 * completeness gate's "a method that throws documents `@throws`" rule
 * (docs/scripts/check-integrity.mjs). Reflection cannot answer this, and the
 * docblock cannot either: a missing `@throws` is exactly what the gate looks
 * for.
 *
 * Token-based, not a regex over the source slice: "throw" occurs in comments
 * and in message strings all over this codebase, and `T_THROW` is the only
 * occurrence that is a statement. Deliberately includes throws inside nested
 * closures — a closure a method hands to the runner still surfaces its
 * exception through that call, and the alternative (tracking closure scope)
 * would need a parser for a checker whose finding is "write a docblock line".
 */
function bodyThrows(ReflectionMethod $method): bool
{
    $file = $method->getFileName();
    $start = $method->getStartLine();
    $end = $method->getEndLine();

    if ($file === false || $start === false || $end === false) {
        return false; // internal or eval'd — nothing to read
    }

    $lines = file($file);
    if ($lines === false) {
        return false;
    }

    $source = '<?php ' . implode('', array_slice($lines, $start - 1, $end - $start + 1));

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_THROW) {
            return true;
        }
    }

    return false;
}

function defaultValueLiteral(ReflectionParameter $param): ?string
{
    if (!$param->isDefaultValueAvailable()) {
        return null;
    }

    $constName = $param->getDefaultValueConstantName();
    if ($constName !== null) {
        // getDefaultValueConstantName() reports the namespace-qualified name
        // PHP tries FIRST at runtime — even for an unqualified global constant
        // like `PHP_INT_MIN` used inside a namespaced file, which actually
        // falls through to the global constant because no such namespaced one
        // exists. Found live 2026-08-09 on IntArbitrary's constructor
        // defaults, which rendered as
        // "Rasuvaeff\PropertyTesting\Arbitrary\PHP_INT_MIN" — not a real
        // constant, and not what the source says.
        if (defined($constName)) {
            return $constName;
        }
        $globalName = substr($constName, strrpos($constName, '\\') + 1);
        if (defined($globalName)) {
            return $globalName;
        }

        return $constName; // unresolved either way; keep the raw form as a signal
    }

    $value = $param->getDefaultValue();

    return match (true) {
        is_string($value) => var_export($value, true),
        is_array($value) => $value === [] ? '[]' : var_export($value, true),
        default => var_export($value, true),
    };
}

$factory = DocBlockFactory::createInstance();
$contextFactory = new ContextFactory();
$report = [];

foreach (roots($coreDir, $workspaceDir, $coreVersion, $testoVersion, $phpunitVersion) as $root) {
    foreach (findPhpFiles($root['srcDir']) as $path) {
        $className = classNameFromPath($root['srcDir'], $root['nsPrefix'], $path);
        if (!class_exists($className) && !interface_exists($className) && !enum_exists($className)) {
            continue;
        }

        $reflection = new ReflectionClass($className);
        // Only classes physically declared in THIS root's src/ belong to it —
        // the shared PSR-4 prefix means class_exists() alone can't attribute
        // ownership (all three autoload through one merged loader).
        if ($reflection->getFileName() !== $path) {
            continue;
        }

        $classDoc = parseDocBlock($factory, $contextFactory, $reflection);
        $relativePath = substr($path, strlen($root['srcDir']) + 1);

        $constructorParams = [];
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $className) {
            $ctorDoc = parseDocBlock($factory, $contextFactory, $constructor);
            foreach ($constructor->getParameters() as $param) {
                $promoted = $param->isPromoted();
                $property = $promoted ? $reflection->getProperty($param->getName()) : null;
                $constructorParams[] = [
                    'name' => $param->getName(),
                    'type' => $ctorDoc['params'][$param->getName()]['type'] ?? typeToString($param->getType()) ?? '',
                    'description' => $ctorDoc['params'][$param->getName()]['description'] ?? '',
                    'default' => defaultValueLiteral($param),
                    'promoted' => $promoted,
                    'promotedVisibility' => $property?->isPublic() ? 'public' : ($property?->isProtected() ? 'protected' : 'private'),
                    'readonly' => $property?->isReadOnly() ?? false,
                ];
            }
        }

        $methods = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue; // inherited, not this class's own contract
            }
            if ($method->isConstructor()) {
                continue; // reported above as constructorParams
            }

            $methodDoc = parseDocBlock($factory, $contextFactory, $method);

            $inheritedFrom = null;
            $prototype = prototypeOf($method);
            if ($prototype !== null) {
                $prototypeDoc = parseDocBlock($factory, $contextFactory, $prototype);
                $merged = inheritDoc($methodDoc, $prototypeDoc);
                if ($merged !== $methodDoc) {
                    $inheritedFrom = $prototype->getDeclaringClass()->getName();
                    $methodDoc = $merged;
                }
            }

            $params = [];
            foreach ($method->getParameters() as $param) {
                $params[] = [
                    'name' => $param->getName(),
                    'type' => $methodDoc['params'][$param->getName()]['type'] ?? typeToString($param->getType()) ?? '',
                    'description' => $methodDoc['params'][$param->getName()]['description'] ?? '',
                    'default' => defaultValueLiteral($param),
                    'variadic' => $param->isVariadic(),
                ];
            }

            $methods[] = [
                'name' => $method->getName(),
                'static' => $method->isStatic(),
                'params' => $params,
                'returnType' => typeToString($method->getReturnType()),
                'summary' => $methodDoc['summary'],
                'description' => $methodDoc['description'],
                'throws' => $methodDoc['throws'],
                'throwsInBody' => bodyThrows($method),
                'inheritedFrom' => $inheritedFrom,
                'see' => $methodDoc['see'],
                'deprecated' => $methodDoc['deprecated'],
                'attributes' => array_map(static fn(ReflectionAttribute $a): string => $a->getName(), $method->getAttributes()),
                'startLine' => $method->getStartLine(),
            ];
        }

        $declaredProperties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $className) {
                continue;
            }
            if ($constructor !== null && in_array($property->getName(), array_column($constructorParams, 'name'), true)) {
                continue; // already reported as a promoted constructor param
            }
            $declaredProperties[] = [
                'name' => $property->getName(),
                'type' => typeToString($property->getType()),
                'readonly' => $property->isReadOnly(),
            ];
        }

        $constants = [];
        foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $className) {
                continue;
            }
            $constDoc = parseDocBlock($factory, $contextFactory, $constant);
            $value = $constant->getValue();
            $constants[] = [
                'name' => $constant->getName(),
                'type' => $constDoc['params']['']['type'] ?? (is_array($value) ? 'array' : get_debug_type($value)),
                'summary' => $constDoc['summary'],
                'value' => is_scalar($value) || $value === null ? $value : null,
            ];
        }

        $enumCases = [];
        if ($reflection->isEnum()) {
            $enumReflection = new ReflectionEnum($className);
            $isBacked = $enumReflection->isBacked();
            foreach ($enumReflection->getCases() as $case) {
                // getCases() is typed to return ReflectionEnumUnitCase, but for a
                // backed enum every case PHP hands back is actually a
                // ReflectionEnumBackedCase (the subtype adding getBackingValue()).
                $enumCases[] = [
                    'name' => $case->getName(),
                    'backingValue' => $isBacked && $case instanceof ReflectionEnumBackedCase ? $case->getBackingValue() : null,
                ];
            }
        }

        $report[] = [
            'root' => $root['label'],
            'rootVersion' => $root['version'],
            'rootReference' => $root['reference'],
            'class' => $className,
            'kind' => match (true) {
                $reflection->isInterface() => 'interface',
                $reflection->isEnum() => 'enum',
                default => 'class',
            },
            'isApi' => $classDoc['isApi'],
            'isAbstract' => $reflection->isAbstract() && !$reflection->isInterface(),
            'isThrowable' => $reflection->implementsInterface(\Throwable::class),
            'summary' => $classDoc['summary'],
            'description' => $classDoc['description'],
            'deprecated' => $classDoc['deprecated'],
            'see' => $classDoc['see'],
            'extensionTags' => $classDoc['extensionTags'],
            'extends' => ($reflection->getParentClass() ?: null)?->getName(),
            'implements' => $reflection->getInterfaceNames(),
            'attributes' => array_map(static fn(ReflectionAttribute $a): string => $a->getName(), $reflection->getAttributes()),
            'constructorParams' => $constructorParams,
            'publicProperties' => $declaredProperties,
            'publicMethods' => $methods,
            'constants' => $constants,
            'enumCases' => $enumCases,
            'sourceUrl' => $root['repoBlob'] . $relativePath . '#L' . $reflection->getStartLine(),
        ];
    }
}

// "Who implements this interface" — computed once over the whole report so
// an interface's page can list every implementer across all three roots,
// not just the ones declared in the same package.
$implementers = [];
foreach ($report as $entry) {
    foreach ($entry['implements'] as $interface) {
        $implementers[$interface][] = $entry['class'];
    }
}
foreach ($report as &$entry) {
    $entry['implementedBy'] = $implementers[$entry['class']] ?? [];
}
unset($entry);

usort($report, static fn(array $a, array $b): int => $a['class'] <=> $b['class']);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
