import { readFileSync, writeFileSync, mkdirSync, readdirSync, rmSync, existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

// Renders docs/scripts/api-snapshot.json (reflect-api.php, multi-root: core +
// both adapters) into docs/src/api/**. See property-testing-evolution-plan.md
// §I.2/§I.5.2. EN-only (Stage I.0) — no RU output, unlike the frozen 2.x site.

const scriptsDir = dirname(fileURLToPath(import.meta.url))
const docsDir = join(scriptsDir, '..')
const apiDir = join(docsDir, 'src', 'api')
const classesDir = join(apiDir, 'classes')

import { NAMESPACE_PREFIX, pageLink, relativePath, shortName } from './api-pages.mjs'

const ROOT_LABEL = { core: 'core', testo: 'testo adapter', phpunit: 'PHPUnit adapter' }
const ROOT_REPO = {
    core: 'https://github.com/rasuvaeff/property-testing-core',
    testo: 'https://github.com/rasuvaeff/property-testing-testo',
    phpunit: 'https://github.com/rasuvaeff/property-testing-phpunit',
}

function shortenType(type) {
    if (type === undefined || type === null || type === '') {
        return 'mixed'
    }
    // Strip the shared namespace prefix only — collapsing every remaining
    // backslash would concatenate the sub-namespace into the class name
    // (Arbitrary\ArrayArbitrary -> "ArbitraryArrayArbitrary", found live
    // 2026-08-09 on ArbitraryInterface's "Implemented by" list).
    return type.replaceAll(NAMESPACE_PREFIX, '')
}

// A bare backslash-namespaced FQCN, optionally leading-backslash-prefixed:
// docblock-resolved types always have the leading backslash ("\Rasuvaeff\...",
// from stripInlineTags()'s {@see} unwrap); native ReflectionType::__toString()
// never does ("Rasuvaeff\PropertyTesting\StateMachine\Command") even though
// it is just as fully qualified. Both must match as ONE contiguous token —
// matching only from the first backslash split "Rasuvaeff" off as bare
// leading text in the no-prefix case (found live 2026-08-09, PostconditionViolation's
// $command param rendered as "Rasuvaeff`PropertyTesting\StateMachine\Command`").
const FQCN_RE = /\\?[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)+/

// Renders a type/prose string as safe, single-purpose markdown: a lone FQCN
// becomes a link (or shortened code text if it has no @api page); anything
// more complex — a union, a psalm generic (`list<string>`, `Foo<Bar>`), a
// nullable `?Foo` — is left ENTIRELY inside one backtick span rather than
// letting individual pieces link while stray `<`/`>`/`|` sit as bare text.
// That split matters structurally, not just cosmetically: markdown-it treats
// a code span's content as opaque, but bare `<...>` in a table cell or
// paragraph is parsed as an HTML/Vue tag by VitePress's Vue SFC compiler —
// found live 2026-08-09, `list<string>` unwrapped crashed the whole build
// ("Element is missing end tag"), and a partially-linked generic
// (`ArbitraryInterface<TValue>` with only the two names linked) would have
// left its `<`/`>` exposed the same way.
// Whole-string forms linkType treats as "a single type name, try to link it":
// a namespaced FQCN (FQCN_RE) or a bare global identifier with no namespace
// at all (RuntimeException, Stringable, Throwable — every extends/implements
// value that isn't part of this family). The latter is deliberately NOT part
// of FQCN_RE itself: that shared regex also drives linkifyProse()'s
// free-text search, where a bare single word ("the", "value", "int") must
// NEVER match — only linkType's known-single-type-cell callers need it.
const WHOLE_TYPE_RE = new RegExp(`^(?:${FQCN_RE.source}|\\\\?[A-Za-z_][A-Za-z0-9_]*)$`)

function linkType(type, apiPagesByClass) {
    if (type === undefined || type === null || type === '') {
        return 'mixed'
    }

    const trimmed = type.trim()
    if (WHOLE_TYPE_RE.test(trimmed)) {
        const clean = trimmed.replace(/^\\/, '')
        const page = apiPagesByClass.get(clean)
        const label = shortenType(clean)
        return page !== undefined ? `[\`${label}\`](${page})` : `\`${label}\``
    }

    return `\`${shortenType(trimmed)}\``
}

// For prose (summaries/descriptions), unlike table-cell types, embedded FQCNs
// should still link even when surrounded by plain sentences — there is no
// competing "whole string is one type expression" case to protect against,
// and every match is already backslash-delimited so no bare `<`/`>` risk exists.
function linkifyProse(text, apiPagesByClass) {
    return text.replace(new RegExp(FQCN_RE.source, 'g'), (fqcn) => {
        const clean = fqcn.replace(/^\\/, '')
        const page = apiPagesByClass.get(clean)
        const label = shortenType(clean)
        return page !== undefined ? `[\`${label}\`](${page})` : `\`${label}\``
    })
}

function formatParams(params) {
    return params.map((p) => `${shortenType(p.type)} $${p.name}${p.default !== null ? ` = ${p.default}` : ''}`).join(', ')
}

function methodSignature(method) {
    const prefix = method.static ? 'static ' : ''
    const returns = shortenType(method.returnType)
    return `${prefix}${method.name}(${formatParams(method.params)}): ${returns}`
}

// A fenced ```php block reads far better than an inline-code heading once a
// signature has enough params to wrap mid-identifier.
function methodSignatureBlock(method) {
    const oneLine = methodSignature(method)
    if (method.params.length <= 2 && oneLine.length <= 88) {
        return oneLine
    }
    const prefix = method.static ? 'static ' : ''
    const returns = shortenType(method.returnType)
    const params = method.params.map((p) => `    ${shortenType(p.type)} $${p.name}${p.default !== null ? ` = ${p.default}` : ''},`).join('\n')
    return `${prefix}${method.name}(\n${params}\n): ${returns}`
}

function firstSentence(text, maxLen) {
    const flat = text.replace(/\s+/g, ' ').trim()
    if (flat === '') return ''
    const boundary = flat.slice(0, maxLen + 1).search(/[.!?](\s|$)/)
    if (boundary !== -1 && boundary <= maxLen) return flat.slice(0, boundary + 1)
    if (flat.length <= maxLen) return flat
    const truncated = flat.slice(0, maxLen - 1)
    const lastSpace = truncated.lastIndexOf(' ')
    return (lastSpace > 0 ? truncated.slice(0, lastSpace) : truncated).trimEnd() + '…'
}

// stripInlineTags() in reflect-api.php unwraps {@see X}/{@link X} down to the
// bare `\Fully\Qualified\Name` — linkType() here is what turns that leftover
// reference into a page link (or shortened plain text), so prose and type
// signatures share one link-resolution path instead of two.
function renderProse(text, apiPagesByClass) {
    return linkifyProse(text, apiPagesByClass)
        .split('\n\n')
        .map((p) => p.trim())
        .filter(Boolean)
        .join('\n\n')
}

// The one function every piece of docblock free text MUST pass through
// before landing in a table cell or a single-line bullet: entity-escape
// `<`/`>` first (raw psalm-style generics like "array<string, Foo>" show up
// routinely in @param prose, not just in types — found live 2026-08-09 on
// Property's own $generators/$examples param descriptions, which crashed
// the build the same way `list<string>` did in a type column), THEN
// linkify FQCNs (backslash sequences are untouched by entity-escaping, so
// this order is safe), THEN neutralise `|`/newlines for the table syntax
// itself.
function escapeCell(text, apiPagesByClass) {
    const entitySafe = text.replace(/</g, '&lt;').replace(/>/g, '&gt;')
    const linked = apiPagesByClass !== undefined ? linkifyProse(entitySafe, apiPagesByClass) : entitySafe
    return linked.replace(/\|/g, '\\|').replace(/\n/g, ' ')
}

const BANNER = '<!-- AUTO-GENERATED by docs/scripts/generate-api.mjs from docs/scripts/api-snapshot.json (docs/scripts/reflect-api.php) — do not edit this file directly. -->'

function renderClass(entry, apiPagesByClass) {
    const name = shortName(entry.class)
    const rootLabel = ROOT_LABEL[entry.root]
    const kindLabel = { class: 'Class', interface: 'Interface', enum: 'Enum' }[entry.kind]
    const description = entry.summary ? firstSentence(entry.summary, 155) : `${name} — ${entry.kind} in the property-testing API reference (${rootLabel}).`

    const lines = []
    lines.push('---')
    lines.push(`title: "${name}"`)
    lines.push(`description: ${JSON.stringify(description)}`)
    lines.push('---')
    lines.push('')
    lines.push(BANNER)
    lines.push('')
    lines.push(`# \`${name}\``)
    lines.push('')
    lines.push(`\`${entry.class}\``)
    lines.push('')

    const badges = [`**${kindLabel}**`, `**Package:** [property-testing-${entry.root}](${ROOT_REPO[entry.root]})`, `[Source](${entry.sourceUrl})`]
    if (entry.rootVersion) {
        badges.push(`**Version:** ${entry.rootVersion}`)
    }
    lines.push(badges.join(' — '))
    lines.push('')

    if (entry.deprecated) {
        lines.push(`::: warning Deprecated`)
        lines.push(entry.deprecated)
        lines.push(':::')
        lines.push('')
    }

    if (entry.extends || entry.implements.length > 0 || entry.implementedBy.length > 0) {
        if (entry.extends) {
            lines.push(`**Extends:** ${linkType('\\' + entry.extends, apiPagesByClass)}`)
            lines.push('')
        }
        if (entry.implements.length > 0) {
            lines.push(`**Implements:** ${entry.implements.map((i) => linkType('\\' + i, apiPagesByClass)).join(', ')}`)
            lines.push('')
        }
        if (entry.implementedBy.length > 0) {
            lines.push(`**Implemented by:** ${entry.implementedBy.map((i) => linkType('\\' + i, apiPagesByClass)).join(', ')}`)
            lines.push('')
        }
    }

    const templateTags = entry.extensionTags?.template
    if (templateTags && templateTags.length > 0) {
        lines.push('**Type parameters:**')
        lines.push('')
        for (const t of templateTags) {
            lines.push(`- \`${t}\``)
        }
        lines.push('')
    }

    if (entry.summary) {
        lines.push(renderProse(entry.summary, apiPagesByClass))
        lines.push('')
    }
    if (entry.description) {
        lines.push(renderProse(entry.description, apiPagesByClass))
        lines.push('')
    }

    if (entry.see && entry.see.length > 0) {
        lines.push(`**See also:** ${entry.see.map((s) => `\`${s}\``).join(', ')}`)
        lines.push('')
    }

    if (entry.constants.length > 0) {
        lines.push('## Constants')
        lines.push('')
        lines.push('| Constant | Type | Value | Description |')
        lines.push('|---|---|---|---|')
        for (const c of entry.constants) {
            const value = c.value === null ? '' : `\`${JSON.stringify(c.value)}\``
            lines.push(`| \`${c.name}\` | \`${shortenType(c.type)}\` | ${value} | ${escapeCell(c.summary, apiPagesByClass)} |`)
        }
        lines.push('')
    }

    if (entry.enumCases.length > 0) {
        lines.push('## Cases')
        lines.push('')
        lines.push('| Case | Backing value |')
        lines.push('|---|---|')
        for (const c of entry.enumCases) {
            lines.push(`| \`${c.name}\` | ${c.backingValue === null ? '—' : `\`${JSON.stringify(c.backingValue)}\``} |`)
        }
        lines.push('')
    }

    if (entry.constructorParams.length > 0) {
        lines.push('## Constructor')
        lines.push('')
        lines.push('```php')
        lines.push('__construct(')
        for (const p of entry.constructorParams) {
            lines.push(`    ${shortenType(p.type)} $${p.name}${p.default !== null ? ` = ${p.default}` : ''},`)
        }
        lines.push(')')
        lines.push('```')
        lines.push('')
        lines.push('| Parameter | Type | Default | Description |')
        lines.push('|---|---|---|---|')
        for (const p of entry.constructorParams) {
            lines.push(`| \`$${p.name}\` | ${linkType(p.type, apiPagesByClass)} | ${p.default !== null ? `\`${p.default}\`` : '*required*'} | ${escapeCell(p.description, apiPagesByClass)} |`)
        }
        lines.push('')
    }

    if (entry.publicProperties.length > 0) {
        lines.push('## Properties')
        lines.push('')
        lines.push('| Property | Type | Readonly | Description |')
        lines.push('|---|---|---|---|')
        for (const prop of entry.publicProperties) {
            lines.push(`| \`${prop.name}\` | \`${shortenType(prop.type)}\` | ${prop.readonly ? 'yes' : 'no'} | ${escapeCell(prop.summary ?? '', apiPagesByClass)} |`)
        }
        lines.push('')
    }

    if (entry.publicMethods.length > 0) {
        lines.push('## Methods')
        lines.push('')
        for (const method of entry.publicMethods) {
            lines.push(`### ${method.name}()`)
            lines.push('')
            lines.push('```php')
            lines.push(methodSignatureBlock(method))
            lines.push('```')
            lines.push('')
            if (method.deprecated) {
                lines.push(`::: warning Deprecated\n${method.deprecated}\n:::`)
                lines.push('')
            }
            if (method.summary) {
                lines.push(renderProse(method.summary, apiPagesByClass))
                lines.push('')
            }
            // reflect-api.php fills an #[Override] implementation's empty
            // docblock fields from the declaration it implements. Saying so
            // on the page is not decoration: the reader has to know the text
            // describes the contract, not this implementation's specifics.
            if (method.inheritedFrom) {
                lines.push(`*Documentation inherited from ${linkType('\\' + method.inheritedFrom, apiPagesByClass)}.*`)
                lines.push('')
            }
            const documentedParams = method.params.filter((p) => p.description !== '')
            if (documentedParams.length > 0) {
                for (const p of documentedParams) {
                    lines.push(`- \`$${p.name}\` — ${escapeCell(p.description, apiPagesByClass)}`)
                }
                lines.push('')
            }
            if (method.throws.length > 0) {
                lines.push('**Throws:**')
                lines.push('')
                for (const t of method.throws) {
                    lines.push(`- ${linkType(t.type.startsWith('\\') ? t.type : '\\' + t.type, apiPagesByClass)}${t.description ? ` — ${t.description}` : ''}`)
                }
                lines.push('')
            }
            if (method.description) {
                lines.push(renderProse(method.description, apiPagesByClass))
                lines.push('')
            }
        }
    }

    if (
        entry.publicProperties.length === 0 &&
        entry.publicMethods.length === 0 &&
        entry.constants.length === 0 &&
        entry.constructorParams.length === 0 &&
        entry.enumCases.length === 0
    ) {
        lines.push('No public members beyond what is documented above.')
        lines.push('')
    }

    return lines.join('\n')
}

function renderIndex(entries) {
    const lines = []
    lines.push('---')
    lines.push('title: API reference')
    lines.push('description: "Generated from reflection over all three packages\' src/ — one page per @api class/interface."')
    lines.push('---')
    lines.push('')
    lines.push(BANNER)
    lines.push('')
    lines.push('# API reference')
    lines.push('')
    lines.push(
        'Generated by reflection (`docs/scripts/reflect-api.php`) over all three packages\' `src/`, ' +
            'not written by hand — every signature, parameter and default value here is read straight ' +
            'from the code, not transcribed from it. Only `@api`-annotated types get a page; `@internal` ' +
            'classes are implementation detail and stay undocumented on purpose.',
    )
    lines.push('')

    for (const root of ['core', 'testo', 'phpunit']) {
        const rootEntries = entries.filter((e) => e.root === root && e.isApi).sort((a, b) => a.class.localeCompare(b.class))
        if (rootEntries.length === 0) continue
        lines.push(`## ${ROOT_LABEL[root][0].toUpperCase()}${ROOT_LABEL[root].slice(1)}`)
        lines.push('')
        lines.push('| Type | Kind | Summary |')
        lines.push('|---|---|---|')
        for (const e of rootEntries) {
            const kindLabel = { class: 'class', interface: 'interface', enum: 'enum' }[e.kind]
            lines.push(`| [\`${shortName(e.class)}\`](${pageLink(e.class)}) | ${kindLabel} | ${escapeCell(firstSentence(e.summary, 100), apiPagesByClass)} |`)
        }
        lines.push('')
    }

    return lines.join('\n')
}

function renderExceptions(entries) {
    const lines = []
    lines.push('---')
    lines.push('title: Exceptions')
    lines.push('description: "Every exception type the family throws and which operation raises it."')
    lines.push('---')
    lines.push('')
    lines.push(BANNER)
    lines.push('')
    lines.push('# Exceptions')
    lines.push('')
    lines.push('Every `@api` type across all three packages that implements `Throwable`.')
    lines.push('')

    const throwables = entries.filter((e) => e.isApi && e.isThrowable).sort((a, b) => a.class.localeCompare(b.class))
    if (throwables.length === 0) {
        lines.push('None found in this snapshot.')
        lines.push('')
        return lines.join('\n')
    }

    lines.push('| Exception | Package | Extends | Summary |')
    lines.push('|---|---|---|---|')
    const apiPagesByClass = new Map(entries.filter((e) => e.isApi).map((e) => [e.class, pageLink(e.class)]))
    for (const e of throwables) {
        lines.push(
            `| [\`${shortName(e.class)}\`](${pageLink(e.class)}) | ${e.root} | ${e.extends ? linkType(e.extends, apiPagesByClass) : '—'} | ${escapeCell(firstSentence(e.summary, 100), apiPagesByClass)} |`,
        )
    }
    lines.push('')

    return lines.join('\n')
}

function clean(dir) {
    try {
        for (const entry of readdirSync(dir)) {
            rmSync(join(dir, entry), { recursive: true, force: true })
        }
    } catch {
        // directory does not exist yet
    }
}

const snapshotPath = join(scriptsDir, 'api-snapshot.json')
if (!existsSync(snapshotPath)) {
    console.error(`Missing ${snapshotPath} — run reflect-api.php first (see its header comment).`)
    process.exit(1)
}

const entries = JSON.parse(readFileSync(snapshotPath, 'utf8'))
const apiEntries = entries.filter((e) => e.isApi)
const apiPagesByClass = new Map(apiEntries.map((e) => [e.class, pageLink(e.class)]))

mkdirSync(classesDir, { recursive: true })
clean(classesDir)

for (const entry of apiEntries) {
    const relPath = relativePath(entry.class)
    const outPath = join(classesDir, `${relPath}.md`)
    mkdirSync(dirname(outPath), { recursive: true })
    writeFileSync(outPath, renderClass(entry, apiPagesByClass) + '\n', 'utf8')
}

writeFileSync(join(apiDir, 'index.md'), renderIndex(entries) + '\n', 'utf8')
writeFileSync(join(apiDir, 'exceptions.md'), renderExceptions(entries) + '\n', 'utf8')

console.log(`Generated ${apiEntries.length} API class pages into docs/src/api/classes/**, plus index.md and exceptions.md.`)
