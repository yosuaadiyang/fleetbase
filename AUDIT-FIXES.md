# Contrust public audit fixes — 2026-10-13

Base deployment: `0d69eab49f235b552a741c3da41f91b4ac32d0bf`, branch
`claude/intelligent-goodall-vjsmf9`. The console retains version 0.7.68 and the
existing Contrust branding.

Changes:

- Login and customer portal carry `identity` into password reset, including
  clearing a stale reset address when the login field is empty.
- A shared parser preserves legacy naive UTC Carbon tokens and zoned ISO expiry
  timestamps, rejecting malformed base64/UTF-8 and invalid calendar dates.
- Coordinate validation accepts zero axes, numeric strings and bounded finite
  values. A Place's unset `(0,0)` placeholder remains invalid. Payload coordinate
  order remains GeoJSON longitude/latitude and Leaflet latitude/longitude.
- Session loader messages use textContent; rejected and synchronously throwing
  invalidation both clean up the overlay and permit retry.
- Public tracking initializes its map without fetching private fleet coordinates.
- Signup autocomplete and an unrestricted viewport improve form and zoom behavior.
  The phone label targets its actual input. Input-label tooltips render lazily and
  stay within the viewport so hidden help text does not create horizontal overflow.
  Signup content grows inside a scrollable viewport so 200% text does not clip the
  heading above the reachable area; help controls respond to keyboard focus and
  have screen-reader descriptions. Closed help is unmounted to prevent overflow.
- Native Intl stays in use for supported active languages. The intl service loads
  a missing language's polyfills/data before selecting it; newer selections win
  over a pending older selection. Unsupported inactive translation hydration does
  not report a misleading MISSING_DATA error.
- Fingerprinted assets receive immutable caching; HTML and runtime/extension
  manifests stay fresh. Missing assets do not get immutable caching. Nginx
  version disclosure is disabled. Retain the previous fingerprinted assets when
  packaging a release so tabs opened before deployment can finish loading them.
- Caddy supplies HSTS, nosniff, referrer/permissions policy and same-origin framing
  protection. Only `frame-ancestors` is an enforced CSP. The broader resource CSP
  remains Report-Only pending authenticated module verification.
- Runtime configuration is fetched with no-store on each boot. A valid one-hour
  cache is only an offline fallback, so routing endpoint changes and rollback take
  effect on reload instead of waiting for localStorage expiry.
- Optional, privately served Indonesia routing configuration and a preparation
  pipeline are supplied in `docker/routing/README.md`.

Addon fixes live in `console/patches` and are applied by pnpm using the lockfile.
Do not edit installed node_modules to maintain them. The normal Docker build
copies patches before frozen dependency installation. No dependency versions were
upgraded by these patch records.

Build the console with Node 22. The production pipeline uses runtime configuration
and `SOURCE_CODE_URL` to offer this modified source archive to users through Legal.
`console/Dockerfile.prebuilt` packages a prebuilt `console/dist` with Nginx, avoiding
a memory-intensive Ember build on the production VPS.

Relevant regression suite (Chrome, timezone Asia/Jakarta):

```sh
cd console
TZ=Asia/Jakarta ember test --filter='/public audit|auth\/login|auth\/two-fa|load-intl-polyfills|onboarding\/form|intl locale loading/'
```

The deployment operator must preserve local runtime API/socket settings and custom
Caddy sites, keep the previous image/configuration as rollback material, validate
Nginx/Caddy, and verify public flows and API health after deployment. The original
audit did not test authenticated tenant isolation, billing or real order data;
the user currently has no dedicated test account/order for those acceptance tests.

## Authenticated QA fixes — 2026-10-15

- Point serialization converts valid text coordinates to JSON numbers, preserves
  longitude/latitude order and rejects blanks, non-finite and out-of-range inputs.
  Manual Place creation previously failed in the API GeoJSON parser because
  positions contained strings. Four real Ember transform regressions cover this.
- Live order panels exclude unsaved/deleted Ember Data records and empty public
  ids. Opening/canceling New Order no longer introduces Undefined draft rows.
- All sixteen console locales define the accessible search label used by resource
  headers, instead of announcing a missing-translation diagnostic.
- Fleet import uses a self-hosted, empty XLSX template instead of the missing
  upstream S3 object. The first row is Name; other columns are not supported by
  the pinned Fleet importer. One-row upload/import was verified with labeled
  synthetic data.

Smoke navigation is separate from complete transaction/permission coverage.
Do not infer tenant isolation, dispatch, provider delivery or payment behavior
from a rendered screen or a test account with Administrator access.

Public tracking now projects an explicit response contract, recursively excluding
internal notes, comments, files, company/customer identifiers, contact/security
fields and financial metadata. The boundary is scoped to the public lookup
controller action; authenticated order endpoints retain their contract. Malformed
tracking query input is rejected before the typed controller. Unknown ETA remains
null and is displayed as unavailable instead of a fabricated zero seconds.
