# Operational content reporting — selection boundary

This is a private candidate workflow, disabled by default with
`CONTRUST_OPERATIONAL_REPORTS_ENABLED=false`. The currently deployed chat controls
and policy remain unchanged. Candidate implementation and synthetic verification
do not activate production access. Do not enable broader moderation or claim the non-chat release gate
complete from these selector tests.

## Selected records

`OperationalReportContext` supports an assigned-order comment, an Issue, a Fuel
Report or a POD reference. Every lookup rechecks the live account, current company,
active membership and linked driver, and uses bound values. A driver can select
only comments/POD of their assigned order or their own Issue/Fuel Report. A
staff-linked driver does not inherit staff access. Deleted/revoked relationships,
foreign companies and nonexistent records return no context.

Staff selection requires `staff_targets`: exact public record IDs already
authorized by the original resource policy, including any row-level IAM rules.
Resource permission names or a general Administrator label alone must not be
converted into this list. Never fill the trusted scope from a request body. An
HTTP integration must also bind an unexpired authenticated token, check its
abilities, and reauthorize on submission; this class is not a replacement for
those entry-point checks.

The returned data is limited to the selected type/reference, associated order
reference, an internal author reference when fresh active membership permits it,
and up to 4,000 characters of the selected comment/Issue text. It does not select
location, amount, odometer, file bytes, file paths/URLs or surrounding comments.
The proof subject or current order assignee is never guessed to be its uploader.
Do not serialize the internal author UUID directly in a client preview.

## Implemented candidate workflow and remaining release checks

1. Authorize the original record before a preview. Display exactly what a report
   would share, with the receiving organization and no automatic GPS/photo/cost.
2. Require the user's own choice to share the selected context. Bind its preview
   to the actor, object/version and expiry; recheck access and content before a
   write. A changed object must not silently replace the previewed content.
3. Use a stable request ID and payload hash for uncertain retries. Record only
   the confirmed context encrypted at rest. Do not grant blanket room/object
   access or create notifications, deletions or suspensions automatically.
4. Connect native/web entry points, policy acceptance before contributions,
   bounded owner review, erasure inventory and pending/retry feedback. Verify
   HTTP authorization and actual device behavior, not only input/SQL tests.
5. Publish revised terms/privacy and activate expanded report access only after
   the owner reviews the exact payload/access change. The previous approval was
   for chat context; it does not silently expand to operational content.

## Verification

`php docker/chat/tests/operational-context-test.php` uses synthetic SQLite under
query-only mode. It checks cross-company/driver denial, exact staff targets,
revocation/deletion, private-field exclusion, author attribution, Unicode length
and malformed selectors. The same SQL suite can run in a newly created synthetic
MySQL schema with its exact guarded name; it cannot select an existing production
schema. In-memory context and account inventory tests are now also included in
`scripts/release/run-offline-regressions.sh` without network or production mounts.

Account-erasure preparation separately follows direct POD-file and avatar-file
links, including uploads by another user. That improves discovery only; it does
not authorize deleting a shared record, storage object, provider copy or backup.


## Candidate verification, 2 November 2026

The private HTTP v3 candidate passed14 disabled/prepare and61 enabled checks,
including original staff IAM self.uuid authorization and own-reporter driver
selection. The existing114 delivery checks also passed. The production container
IDs and configuration stayed unchanged and the synthetic volumes were removed.
The full release script now runs operational prepare/enable contracts after its
legacy suites, validates that only the two synthetic feature flags change, and
uses an internal network/log mail. No production credentials or business data
are used by those fixtures.

Android QA36/source3189440 provides per-record buttons, explicit preview/sharing,
scoped durable retry and fresh policy gates. The web candidate provides a shared
**Keamanan konten operasional** entry across engine pages: users choose a type
and paste an ID visible on the original resource before requesting its authorized
preview. No arbitrary object gets fetched by ID without original-resource IAM.
Owner review displays the selected type/reference and escaped context. No report
or review automatically sends notifications, removes content or suspends users.
The old chat report review continues to honor previously confirmed selected
context; pausing new operational submissions does not grant general data access.

Client build verification, physical acceptance, corresponding-source publication,
owner approval of policy2026-11-01.1 and guarded activation remain release steps.
Account-erasure inventory changes are a separate uncommitted work item and do not
implement or authorize production deletion/provider/backup removal.
