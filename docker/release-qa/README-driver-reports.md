# Driver report contracts

DriverReports handles only authenticated managed-driver Issue/FuelReport API
actions. It resolves ownership and vehicle assignment from the session, scopes
list/read/mutation to both driver and reporter, rejects unknown ownership/provider
fields, and blocks edits/deletion of submitted or reviewed fuel reports. Draft
mutation locks the row in a transaction. A valid GeoJSON point is required for
creation by existing non-null spatial schemas; no default coordinates are made.

The required isolated HTTP fixture covers create, confirmed IDs, reload/list,
privacy/cache headers, same-company other drivers, foreign drivers, staff-linked
driver sessions, bootstrap/anonymous denial, spoofed identity/filter fields,
invalid location/value/status inputs, own issue edits, fuel submission, immutable
submitted/approved expenses, and deletion of editable own records. It refuses
non-synthetic database/mail/broadcast settings and removes only its own test rows
and temporary tokens. Local cached-image tests do not replace full release CI or
actual VPS candidate checks. No schema migration or real report creation is needed
for deployment.
