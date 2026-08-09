import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { dirname, join, extname, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import markdownLinkCheck from 'markdown-link-check'

// External links only. check-integrity.mjs already resolves every internal
// link against the source tree and check-anchors.mjs every #fragment against
// the rendered headings — neither can tell whether a Packagist page, a
// php.net manual entry or a GitHub blob still exists, and those are the ones
// that rot without anybody touching this repository.
//
// Deliberately NOT part of `npm run docs:build`: a network blip on someone
// else's host must never be the reason a documentation deploy fails. CI runs
// it as a separate job (see .github/workflows/docs-links.yml).
//
// Usage:
//   node docs/scripts/check-links.mjs               # every page
//   node docs/scripts/check-links.mjs a.md b.md     # only these (PR mode)

const scriptsDir = dirname(fileURLToPath(import.meta.url))
const docsDir = join(scriptsDir, '..')
const srcDir = join(docsDir, 'src')
const pkgDir = join(docsDir, '..')

const NON_CONTENT_DIRS = new Set(['node_modules', '.vitepress', 'public', 'scripts'])

// Generated reference pages carry one repository link per type, all built
// from the same two templates in generate-api.mjs. Checking 82 pages' worth
// of them means ~250 requests to github.com to verify one URL shape.
const SKIP_DIRS = ['api/']

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

const requested = process.argv.slice(2)
const files = (
    requested.length > 0
        ? requested.map((path) => resolve(pkgDir, path)).filter((path) => existsSync(path) && extname(path) === '.md')
        : collectMarkdownFiles(srcDir)
).filter((path) => {
    const rel = relative(srcDir, path)

    return !rel.startsWith('..') && !SKIP_DIRS.some((dir) => rel.startsWith(dir))
})

const options = {
    // Only absolute http(s) links reach the network; everything else is
    // somebody else's check.
    ignorePatterns: [
        { pattern: '^[^h]' },
        { pattern: '^http(?!s?://)' },
        // Links into this repository's own tree are verified offline against
        // the working tree by check-integrity.mjs — on a pull request the
        // file is not on master yet, so the network answer is the opposite of
        // the truth.
        { pattern: '^https://github\\.com/rasuvaeff/property-testing-core/(blob|tree)/' },
    ],
    timeout: '20s',
    retryOn429: true,
    retryCount: 3,
    fallbackRetryDelay: '30s',
    // 403 from a host that dislikes HEAD from CI is not a dead link; a
    // rotted link answers 404/410, and that is what this check is for.
    aliveStatusCodes: [200, 206, 403, 429],
}

const dead = []

for (const file of files) {
    const rel = relative(pkgDir, file)
    const markdown = readFileSync(file, 'utf8')

    const results = await new Promise((resolveResults, rejectResults) => {
        markdownLinkCheck(markdown, { ...options, baseUrl: `file://${dirname(file)}` }, (err, res) =>
            err !== null && err !== undefined ? rejectResults(err) : resolveResults(res),
        )
    })

    for (const result of results) {
        if (result.status === 'dead') {
            dead.push(`${rel} → ${result.link} (HTTP ${result.statusCode ?? 'no response'})`)
        }
    }
}

if (dead.length > 0) {
    console.error(`external link check found ${dead.length} dead link(s):\n`)
    for (const entry of dead) {
        console.error(`  - ${entry}`)
    }
    process.exit(1)
}

console.log(`external link check passed: every external link in ${files.length} page(s) still answers.`)
