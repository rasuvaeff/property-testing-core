// Where a class's generated reference page lives, shared by the generator
// (generate-api.mjs) and the checker (check-integrity.mjs).
//
// Deliberately one module rather than the same three lines in both files:
// the checker's whole job is to assert that the page the generator wrote is
// the page the site links to, and it cannot do that from an independent
// re-derivation of the layout — the two would drift together only by luck.

export const NAMESPACE_PREFIX = 'Rasuvaeff\\PropertyTesting\\'

export function shortName(className) {
    const parts = className.split('\\')

    return parts[parts.length - 1]
}

// Mirrors the class's own sub-namespace under classes/ instead of a flat
// shortName — Testo\VerboseListener and PhpUnit\VerboseListener share a
// short name, so a flat layout would have the second root silently
// overwrite the first's page.
export function relativePath(className) {
    return className.startsWith(NAMESPACE_PREFIX)
        ? className.slice(NAMESPACE_PREFIX.length).replaceAll('\\', '/')
        : className.replaceAll('\\', '/')
}

export function pageLink(className) {
    return `/api/classes/${relativePath(className)}`
}
