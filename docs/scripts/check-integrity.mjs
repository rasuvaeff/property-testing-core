import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs'
import { dirname, join, extname, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

import { pageLink, relativePath, shortName } from './api-pages.mjs'

// Structural checks that run BEFORE `vitepress build` (check-anchors.mjs runs
// after it, on the rendered HTML — see its header for why the split exists).
//
// Ported from the frozen 2.x site (property-testing-evolution-plan.md §I.1)
// with the RU locale branches removed (the family site is EN-only, §I.0) and
// three additions: generated pages live in nested directories now, nav and
// pages must cover each other, and the API reference has a completeness
// ratchet.

const scriptsDir = dirname(fileURLToPath(import.meta.url))
const docsDir = join(scriptsDir, '..')
const srcDir = join(docsDir, 'src')
const pkgDir = join(docsDir, '..')

// The number of documentation holes in the API reference this repository
// currently accepts, per kind. A ratchet, not a baseline to live with: the
// check FAILS when a count grows and WARNS (with the new number) when it
// shrinks, so the budget only ever moves down. See §I.2 — "без гейта «полнее»
// тихо деградирует обратно".
const COMPLETENESS_BUDGET = {
    'type without a summary': 1,
    'method without a summary': 29,
    'parameter without a description': 125,
    'constructor parameter without a description': 115,
    'throwing method without @throws': 13,
}

const errors = []
const warnings = []
const fail = (message) => errors.push(message)
const warn = (message) => warnings.push(message)

// 1. README.md must exist and be non-trivial. (2.x checked README.ru.md too;
//    the site is EN-only now, and the bilingual-README rule is enforced by
//    the monorepo's own conventions, not by a docs build.)
const readmePath = join(pkgDir, 'README.md')
if (!existsSync(readmePath)) {
    fail('README.md is missing.')
} else if (readFileSync(readmePath, 'utf8').trim().length < 200) {
    fail('README.md is suspiciously short (< 200 chars) — looks empty or truncated.')
}

// 2. Every @api type in the reflection snapshot must have a generated page at
//    the path generate-api.mjs writes it to, and every public method/property
//    name must actually appear on that page (catches a generator regression
//    that silently drops members, not just a missing file).
const snapshot = JSON.parse(readFileSync(join(scriptsDir, 'api-snapshot.json'), 'utf8'))
const apiClasses = snapshot.filter((entry) => entry.isApi)
const apiPagePaths = new Set()

for (const entry of apiClasses) {
    const rel = join('api', 'classes', `${relativePath(entry.class)}.md`)
    apiPagePaths.add(rel)
    const pagePath = join(srcDir, rel)

    if (!existsSync(pagePath)) {
        fail(`Missing generated API page for @api type "${entry.class}": src/${rel}`)

        continue
    }

    const content = readFileSync(pagePath, 'utf8')
    for (const method of entry.publicMethods) {
        if (!content.includes(method.name)) {
            fail(`src/${rel} is missing method "${method.name}" from the reflection snapshot.`)
        }
    }
    for (const prop of entry.publicProperties) {
        if (!content.includes(prop.name)) {
            fail(`src/${rel} is missing property "${prop.name}" from the reflection snapshot.`)
        }
    }
}

// 3. No generated page for a type that is not @api — the reference must
//    filter by the @api tag, never leak internals. Recursive: pages sit in
//    per-namespace subdirectories.
function collectFiles(dir, ext) {
    const results = []
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const path = join(dir, entry.name)
        if (entry.isDirectory()) {
            results.push(...collectFiles(path, ext))
        } else if (extname(entry.name) === ext) {
            results.push(path)
        }
    }

    return results
}

const classesDir = join(srcDir, 'api', 'classes')
if (existsSync(classesDir)) {
    for (const file of collectFiles(classesDir, '.md')) {
        const rel = relative(srcDir, file)
        if (!apiPagePaths.has(rel)) {
            fail(`src/${rel} has no corresponding @api entry in the reflection snapshot — a non-@api or removed type leaked into the reference.`)
        }
    }
}

// 4. Every internal link — in the nav/sidebar config and inside a page's own
//    markdown — must resolve to a file on disk.
const NON_CONTENT_DIRS = new Set(['node_modules', '.vitepress', 'public', 'scripts'])

function collectMarkdownFiles(dir) {
    const results = []
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        if (NON_CONTENT_DIRS.has(entry.name)) continue
        const path = join(dir, entry.name)
        if (entry.isDirectory()) {
            results.push(...collectMarkdownFiles(path))
        } else if (extname(entry.name) === '.md') {
            results.push(path)
        }
    }

    return results
}

// Returns the source file an absolute site link points at, or null.
function resolveLink(link) {
    // Strip a query/hash suffix; VitePress `cleanUrls` links omit `.md`.
    const clean = link.split('#')[0].split('?')[0]
    const withoutLeadingSlash = clean.replace(/^\//, '')
    const candidates =
        withoutLeadingSlash === ''
            ? [join(srcDir, 'index.md')]
            : [join(srcDir, withoutLeadingSlash + '.md'), join(srcDir, withoutLeadingSlash, 'index.md')]

    return candidates.find((path) => existsSync(path) && statSync(path).isFile()) ?? null
}

const configPath = join(srcDir, '.vitepress', 'config.ts')
const configSource = readFileSync(configPath, 'utf8')
const linkedPages = new Set()

for (const match of configSource.matchAll(/link:\s*'([^']+)'/g)) {
    const link = match[1]
    if (/^https?:\/\//.test(link)) continue

    const resolved = resolveLink(link)
    if (resolved === null) {
        fail(`config.ts references a link that does not resolve to a file: "${link}"`)
    } else {
        linkedPages.add(relative(srcDir, resolved))
    }
}

// Raw HTML anchors like <a href="/..."> bypass VitePress's `base` rewriting
// (only markdown links and Vue Router links get the base prefix), so they
// point at the domain root on a project-Pages site. Internal links must be
// markdown.
const markdownLinkPattern = /\]\((\/[^)#\s]+)(#[^)\s]*)?\)/g
const rawHtmlAnchorPattern = /<a\s[^>]*href="\/(?!\/)[^"]*"/i

for (const file of collectMarkdownFiles(srcDir)) {
    const rel = relative(srcDir, file)
    const content = readFileSync(file, 'utf8')

    if (rawHtmlAnchorPattern.test(content)) {
        const lineNum = content.split('\n').findIndex((l) => rawHtmlAnchorPattern.test(l)) + 1
        fail(
            `src/${rel}:${lineNum} uses a raw HTML <a href="/..."> internal link — VitePress does not apply \`base\` to it. Use a markdown link instead.`,
        )
    }

    for (const match of content.matchAll(markdownLinkPattern)) {
        const link = match[1]
        if (resolveLink(link) === null) {
            fail(`src/${rel} links to "${link}", which does not resolve to a file.`)
        }
    }
}

// 5. A link into this repository's own tree on GitHub must point at a file
//    that exists here. These cannot be verified over the network the way
//    other external links are (check-links.mjs): on a pull request the file
//    is not on master yet, so a correct link 404s and a link to a renamed
//    file passes once master catches up — exactly backwards. Offline, against
//    the working tree, both cases are answered immediately.
const OWN_REPO_LINK = /https:\/\/github\.com\/rasuvaeff\/property-testing-core\/(?:blob|tree)\/master\/([^)\s#]+)/g

for (const file of collectMarkdownFiles(srcDir)) {
    const rel = relative(srcDir, file)
    if (rel.startsWith('api/')) continue // generated: the URL shape is generate-api.mjs's

    for (const match of readFileSync(file, 'utf8').matchAll(OWN_REPO_LINK)) {
        const target = match[1].replace(/[.,;:]$/, '')
        if (!existsSync(join(pkgDir, target))) {
            fail(`src/${rel} links to "${target}" in this repository on GitHub, but no such path exists in the working tree.`)
        }
    }
}

// 6. Nav and pages must cover each other. The forward direction is check 4
//    above (a nav link with no file); this is the reverse — a page nobody can
//    reach. It replaces the aggregator's section map as the thing that keeps
//    the page set honest: with pages written by hand (§I.1), the only way a
//    new one silently disappears is by never being linked.
//
//    Generated reference pages are exempt: they are reachable through the
//    generated api/index.md table, which check 2 already ties to the
//    snapshot one-to-one. src/index.md is the home page — the site root, not
//    a nav destination.
const UNLINKED_BY_DESIGN = new Set(['index.md'])

for (const file of collectMarkdownFiles(srcDir)) {
    const rel = relative(srcDir, file)
    if (rel.startsWith('api/') || UNLINKED_BY_DESIGN.has(rel)) continue
    if (linkedPages.has(rel)) continue

    fail(`src/${rel} is not reachable from the nav or sidebar in config.ts — add it there, or delete the page.`)
}

// 7. Every core @api type should be mentioned in llms.txt — a weak but cheap
//    proxy for "the compact LLM reference wasn't left behind when the public
//    API grew". Core only: the snapshot spans all three packages, and core's
//    llms.txt has no business listing the adapters' classes.
const llms = readFileSync(join(pkgDir, 'llms.txt'), 'utf8')
for (const entry of apiClasses.filter((e) => e.root === 'core')) {
    const name = shortName(entry.class)
    if (!llms.includes(name)) {
        fail(`llms.txt does not mention @api type "${name}" — update llms.txt when the public API changes.`)
    }
}

// 8. Completeness of the generated reference. Reflection guarantees the
//    signatures are right; nothing guarantees they are explained. Findings
//    are counted per kind and compared against COMPLETENESS_BUDGET above.
const incomplete = { ...Object.fromEntries(Object.keys(COMPLETENESS_BUDGET).map((k) => [k, []])) }
const note = (kind, where) => incomplete[kind].push(where)

// Core only. The snapshot spans all three packages, and the adapters are
// reflected from their PUBLISHED tags — counting their holes here would let
// an adapter release blow the budget and redden this repository's master
// with nothing changed in it. Same reason the llms.txt check above is scoped.
for (const entry of apiClasses.filter((e) => e.root === 'core')) {
    const page = pageLink(entry.class)

    if (entry.summary.trim() === '') {
        note('type without a summary', page)
    }
    for (const param of entry.constructorParams) {
        if (param.description.trim() === '') {
            note('constructor parameter without a description', `${page} __construct($${param.name})`)
        }
    }
    for (const method of entry.publicMethods) {
        if (method.summary.trim() === '') {
            note('method without a summary', `${page} ${method.name}()`)
        }
        if (method.throwsInBody && method.throws.length === 0) {
            note('throwing method without @throws', `${page} ${method.name}()`)
        }
        for (const param of method.params) {
            if (param.description.trim() === '') {
                note('parameter without a description', `${page} ${method.name}($${param.name})`)
            }
        }
    }
}

for (const [kind, found] of Object.entries(incomplete)) {
    const budget = COMPLETENESS_BUDGET[kind]

    if (found.length > budget) {
        const added = found.slice(0, 10).join(', ')
        fail(
            `API reference completeness regressed: ${found.length} × "${kind}", budget is ${budget}. ` +
                `Document the new members instead of raising the budget. Examples: ${added}${found.length > 10 ? ', …' : ''}`,
        )
    } else if (found.length < budget) {
        warn(`API reference improved: only ${found.length} × "${kind}" left (budget ${budget}) — lower COMPLETENESS_BUDGET in this script to lock the gain in.`)
    }
}

const totalIncomplete = Object.values(incomplete).reduce((sum, found) => sum + found.length, 0)

for (const warning of warnings) {
    console.warn(`  warning: ${warning}`)
}

if (errors.length > 0) {
    console.error(`docs integrity check found ${errors.length} problem(s):\n`)
    for (const error of errors) {
        console.error(`  - ${error}`)
    }
    process.exit(1)
}

console.log(
    `docs integrity check passed: ${apiClasses.length} @api types, every page reachable from the nav, all links resolve, ` +
        `${totalIncomplete} documentation hole(s) within budget.`,
)
