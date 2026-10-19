# Dependency security maintenance

The package graph contains browser modules and Ember compilation tools. Audit severity alone does not establish exposure of a production browser or API. Record reachability and the exact lockfile for each release; do not silently suppress advisories.

## Runtime fixes under regression

- SheetJS is vendored from the publisher's 0.20.3 archive; see `vendor/README.md`. Fleet-Ops Excel import uses this override.
- ProseMirror View 1.42.3 validates clipboard slice attributes. Source: https://github.com/ProseMirror/prosemirror-view/commit/2e91a61
- Tiptap 2.27.2 has a maintained backport of the upstream `mergeAttributes` fix. Every executable distribution entry and the TypeScript source defines an own data property for `__proto__`, preventing inherited DOM attributes. This avoids a major editor migration. Source: https://github.com/ueberdosis/tiptap/commit/01d7af8
- Exact patched releases of devalue and fast-uri are pinned in workspace overrides.

The Tiptap version remains within the advisory's version range because this is a backport. Its regression constructs the actual ProseMirror DOM serializer and verifies malicious inherited attributes are absent. Treat an audit finding for this package as requiring patch-integrity verification, not blanket dismissal.

## Build tools

Handlebars 4.7.10 is pinned. The old calendar date adapter's auto-import is upgraded to the already-used auto-import 2 release, removing its Babel 6/webpack 4 compiler chain. Full QUnit, calendar rendering and production-build compatibility must pass before activation.

Remaining audit findings must be separately triaged. Build jobs must compile reviewed source without production credentials or untrusted uploaded documents. Do not declare the dependency audit clean while unresolved findings remain.

## Local audit observations, 19 October 2026

The selected updates reduced the production dependency graph audit from 60 advisories (3 critical) to 4 advisories (0 critical). These are not a claim of four exploitable browser vulnerabilities: Tiptap has a tested security backport; braces and sprintf-js occur in build tooling; tmp requires its latest registry patch. The upstream advisory database and npm audit endpoint differ on availability of unpublished braces/sprintf fixes. No unverified version is installed.

Compiler compatibility: ember-fetch output is named `whatwg-fetch.js` rather than its input name `fetch.js` so broccoli-rollup 5 includes it in the output diff. A browser regression imports the actual fetch module. Workbook import rejects reserved worksheet names before preview/full import accesses inherited dictionary entries.

Defensive build bounds are maintained for braces 3.0.3 (parser nesting plus iterative AST depth/node bounds) and the Node entry of sprintf-js 1.1.3 (format length, width and precision). These are local defensive mitigations, not claims of an upstream release. `security-backports.json` pins patch digests; `scripts/release/check-dependency-security.cjs` exercises adversarial and ordinary inputs, actual workbook imports and tmp 0.2.7 type checks. The standalone minified sprintf browser artifact is not part of this build-entry mitigation and is not used by the console.
