import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { dirname, join, basename } from 'node:path'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'

// The only thing that keeps the cookbook honest (plan §I.4, §I.5.6).
//
// Every case study quotes a real runner output — a counterexample, a shrink
// count, a failure message. All three are produced by code that keeps
// moving: a reflowed message, a new boundary bias, a bumped
// FilesystemCorpus::SEQUENCE_EPOCH, and the quoted block becomes a
// plausible-looking fiction that nothing in the build contradicts. So the
// build runs the scripts and diffs their stdout against the pages.
//
// Not part of `npm run docs:build`: it is the one checker that needs PHP and
// a `composer install`, and the site must stay buildable on a machine that
// has neither. CI runs it as its own step (see .github/workflows/docs.yml);
// locally, `make docs-cookbook` from the package root, which is:
//
//   PHP_BIN="docker run --rm -v $PWD:/app -w /app composer:2 php" \
//     node docs/scripts/check-cookbook.mjs
//
// PHP_BIN is a command PREFIX evaluated by the shell, so a container
// invocation works as well as a bare interpreter. Each script is passed as a
// path relative to the package root, and the package root is both the
// working directory and the container's -w — that is what makes one string
// work inside and outside a container.

const scriptsDir = dirname(fileURLToPath(import.meta.url))
const docsDir = join(scriptsDir, '..')
const srcDir = join(docsDir, 'src')
const pkgDir = join(docsDir, '..')
const cookbookDir = join(srcDir, 'cookbook')
const caseStudyDir = join(pkgDir, 'examples', 'case-studies')

const PHP_BIN = process.env.PHP_BIN ?? 'php'

const errors = []
const fail = (message) => errors.push(message)

const pages = readdirSync(cookbookDir)
    .filter((name) => name.endsWith('.md') && name !== 'index.md')
    .map((name) => basename(name, '.md'))
    .sort()

const scripts = existsSync(caseStudyDir)
    ? readdirSync(caseStudyDir)
          .filter((name) => name.endsWith('.php'))
          .map((name) => basename(name, '.php'))
          .sort()
    : []

for (const slug of pages) {
    if (!scripts.includes(slug)) {
        fail(`src/cookbook/${slug}.md has no examples/case-studies/${slug}.php — a case study whose output cannot be reproduced is not a case study.`)
    }
}
for (const slug of scripts) {
    if (!pages.includes(slug)) {
        fail(`examples/case-studies/${slug}.php has no src/cookbook/${slug}.md — the script is unreferenced.`)
    }
}

// The quoted block is found by an explicit marker, not by position ("the
// first fenced block on the page"): a page is free to show the buggy code,
// the property and the fix in fences of their own, and reordering those must
// not silently repoint the check at a different block.
const OUTPUT_BLOCK = /<!--\s*case-study-output:\s*([a-z0-9-]+)\s*-->\r?\n```[a-z]*\r?\n([\s\S]*?)\r?\n```/g

for (const slug of pages) {
    if (!scripts.includes(slug)) continue

    const pagePath = join(cookbookDir, `${slug}.md`)
    const page = readFileSync(pagePath, 'utf8')
    const blocks = [...page.matchAll(OUTPUT_BLOCK)]

    if (blocks.length === 0) {
        fail(`src/cookbook/${slug}.md has no "<!-- case-study-output: ${slug} -->" marker above its runner-output block — nothing to verify against.`)

        continue
    }
    if (blocks.length > 1) {
        fail(`src/cookbook/${slug}.md has ${blocks.length} case-study-output markers; expected exactly one.`)

        continue
    }

    const [, markerSlug, quoted] = blocks[0]
    if (markerSlug !== slug) {
        fail(`src/cookbook/${slug}.md marks its output block as "${markerSlug}" — the slug must match the page.`)

        continue
    }

    const relScript = join('examples', 'case-studies', `${slug}.php`)
    const run = spawnSync(`${PHP_BIN} ${relScript}`, {
        cwd: pkgDir,
        shell: true,
        encoding: 'utf8',
        // The engine is env-free, but the adapters are not, and a case study
        // is free to grow one: an inherited PROPERTY_DB would replay a stored
        // counterexample and quietly change the output being verified (§I.4).
        env: { ...process.env, PROPERTY_DB: '', PROPERTY_SEED: '', PROPERTY_RUNS: '', PROPERTY_VERBOSE: '' },
    })

    if (run.error !== undefined) {
        fail(`Could not run ${relScript} via PHP_BIN="${PHP_BIN}": ${run.error.message}`)

        continue
    }
    if (run.status !== 0) {
        fail(`${relScript} exited with ${run.status}. stderr:\n${run.stderr.trim()}`)

        continue
    }

    const actual = run.stdout.replace(/\r\n/g, '\n').trimEnd()
    const expected = quoted.replace(/\r\n/g, '\n').trimEnd()

    if (actual !== expected) {
        fail(
            `src/cookbook/${slug}.md quotes output that ${relScript} no longer produces.\n` +
                `      --- quoted on the page ---\n${indent(expected)}\n` +
                `      --- actual output ---\n${indent(actual)}`,
        )
    }
}

function indent(text) {
    return text
        .split('\n')
        .map((line) => `      | ${line}`)
        .join('\n')
}

if (errors.length > 0) {
    console.error(`cookbook check found ${errors.length} problem(s):\n`)
    for (const error of errors) {
        console.error(`  - ${error}`)
    }
    process.exit(1)
}

console.log(`cookbook check passed: ${pages.length} case stud${pages.length === 1 ? 'y' : 'ies'} reproduce the output quoted on their page.`)
