import { defineConfig } from 'vitepress'

// Nav/sidebar are the single source of truth for the page set (see
// property-testing-evolution-plan.md, §I.1/§I.5). docs/scripts/check-integrity.mjs
// enforces both directions: every link here resolves to a file, and every
// hand-written page under src/ is reachable from here — an orphan page is an
// error, not a page that merely nobody linked yet.
const sidebar = [
    {
        text: 'Intro',
        items: [
            { text: 'What is property-testing', link: '/guide/intro/what-is-property-testing' },
            { text: 'Getting started', link: '/guide/intro/getting-started' },
            { text: 'Concepts', link: '/guide/intro/concepts' },
        ],
    },
    { text: 'Shrinking', link: '/guide/shrinking' },
    {
        text: 'Generators',
        items: [
            { text: 'Overview', link: '/guide/generators/index' },
            { text: 'Boundary bias', link: '/guide/generators/boundary-bias' },
            { text: 'Dependent generators (flatMap vs draw)', link: '/guide/generators/dependent' },
            { text: 'Swarm testing', link: '/guide/generators/swarm' },
            { text: 'Custom arbitrary', link: '/guide/generators/custom-arbitrary' },
        ],
    },
    {
        text: 'Controlling runs',
        items: [
            { text: 'Assume vs filter', link: '/guide/controlling-runs/assume-vs-filter' },
            { text: 'Bounding shrink work', link: '/guide/controlling-runs/bounding-shrink' },
            { text: 'Deadlines', link: '/guide/controlling-runs/deadlines' },
            { text: 'Derandomized runs', link: '/guide/controlling-runs/derandomize' },
            { text: 'Run phases', link: '/guide/controlling-runs/phases' },
            { text: 'Replaying a shrink path', link: '/guide/controlling-runs/replaying-a-path' },
            { text: 'Environment overrides', link: '/guide/controlling-runs/env-overrides' },
        ],
    },
    { text: 'Regression corpus', link: '/guide/regression-corpus' },
    { text: 'Explicit examples', link: '/guide/explicit-examples' },
    { text: 'Distribution', link: '/guide/distribution' },
    { text: 'Sampling & export', link: '/guide/sampling-and-export' },
    {
        text: 'State machine',
        items: [
            { text: 'Concepts', link: '/guide/state-machine/concepts' },
            { text: 'Shrinking', link: '/guide/state-machine/shrinking' },
        ],
    },
    { text: 'Recipes', link: '/guide/recipes' },
    { text: 'Security', link: '/guide/security' },
    { text: 'Examples', link: '/guide/examples' },
    { text: 'Migrating from 2.x', link: '/guide/migrating-from-2x' },
    { text: 'Roadmap', link: '/roadmap' },
    {
        text: 'Cookbook',
        items: [
            { text: 'Overview', link: '/cookbook/index' },
            { text: 'Regex accept/reject anchoring', link: '/cookbook/regex-anchor' },
            { text: 'Saturating subtraction', link: '/cookbook/saturating-minus' },
            { text: 'Backoff delay stays within its cap', link: '/cookbook/backoff-cap' },
            { text: 'Deterministic hash bucketing', link: '/cookbook/hash-bucketing' },
            { text: 'Faker vs property', link: '/cookbook/faker-vs-property' },
        ],
    },
    {
        text: 'Adapters',
        items: [
            { text: 'Testo', link: '/adapters/testo' },
            { text: 'PHPUnit', link: '/adapters/phpunit' },
        ],
    },
    {
        text: 'API reference',
        items: [
            { text: 'Overview', link: '/api/index' },
            { text: 'Exceptions', link: '/api/exceptions' },
        ],
    },
]

const SITE_URL = 'https://rasuvaeff.github.io/property-testing-core/'

export default defineConfig({
    title: 'property-testing',
    description:
        'Property-based testing for PHP 8.3+: generate hundreds of random inputs, find the one that falsifies your property, and shrink it to a minimal counterexample. One engine (property-testing-core), two framework adapters (Testo, PHPUnit).',
    base: '/property-testing-core/',
    cleanUrls: true,
    lastUpdated: true,
    sitemap: { hostname: SITE_URL },
    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/property-testing-core/favicon.svg' }],
        ['meta', { name: 'theme-color', content: '#16202B' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:site_name', content: 'property-testing' }],
        ['meta', { name: 'twitter:card', content: 'summary' }],
    ],
    // Per-page canonical + Open Graph/Twitter title & description, mirroring
    // the 2.x site (docs/scripts/../../property-testing/docs/.vitepress/config.ts) —
    // VitePress's static `head` above cannot vary per page.
    transformHead: ({ pageData, title, description }) => {
        const clean = pageData.relativePath.replace(/\.md$/, '').replace(/(^|\/)index$/, '$1')
        const url = SITE_URL + clean

        return [
            ['link', { rel: 'canonical', href: url }],
            ['meta', { property: 'og:title', content: title }],
            ['meta', { property: 'og:description', content: description }],
            ['meta', { property: 'og:url', content: url }],
            ['meta', { name: 'twitter:title', content: title }],
            ['meta', { name: 'twitter:description', content: description }],
        ]
    },
    themeConfig: {
        logo: '/logo-mark.svg',
        search: { provider: 'local' },
        nav: [
            { text: 'Guide', link: '/guide/intro/what-is-property-testing' },
            { text: 'Cookbook', link: '/cookbook/index' },
            { text: 'Adapters', link: '/adapters/testo' },
            { text: 'Roadmap', link: '/roadmap' },
            { text: 'API', link: '/api/index' },
        ],
        sidebar: { '/': sidebar },
        outlineTitle: 'On this page',
        socialLinks: [{ icon: 'github', link: 'https://github.com/rasuvaeff/property-testing-core' }],
        editLink: {
            pattern: 'https://github.com/rasuvaeff/property-testing-core/edit/master/docs/src/:path',
        },
    },
})
