# Account data inventory (operator preflight)

This counts potential account relationships in **one current database**, without deleting, updating or reading record contents. It does not replace a schema/content review, extend any API access, create a deletion endpoint, or verify complete erasure. Do not put this directory in an HTTP document root.

The owner chose on 22 October 2026: **retain data until the account is deleted**, including location history, order/POD/chat/reports and backup copies. There is no additional fixed-day expiry while an account exists. This policy is not approval to delete a live account or all historical backup snapshots.

The helper follows user → membership/driver/contact, participant → messages/attachments, driver/contact → orders, and order → proof/file relationships. It counts candidate rows only. A related row may contain other people's data; its presence is **not authorization to delete it**. Unknown schema/missing tables remain explicit gaps. Polymorphic IDs require operator type review. Embedded names/emails/coordinates, raw payloads, files, logs, other schemas and providers require separate inventory.

Direct `proofs.file_uuid` and `users.avatar_uuid` links are included even when another staff member uploaded a file and its polymorphic subject is absent or different. Missing link columns remain explicit relationship gaps. These references still identify review candidates, not files that may automatically be deleted; another record or person can share the object.

The deployed schema inspected on 2 November 2026 uses `activity`, not only the legacy `activity_log` name. Both are supported independently. The inventory also follows contact photos and chat attachment file references, current token IDs into API/webhook request logs, account notifications, participant-initiated chat logs, driver geofence logs and direct report/admin audit references. Native integer token IDs are preserved without reading token values. These are candidate counts: polymorphic types, shared records, historical logs whose token rows have already been deleted, email-based login/reset records and personal data embedded in JSON/text still require separate review. Missing tables remain explicit rather than being reported as zero rows.

## Tests

`php docker/privacy/tests/account-inventory-test.php`

Tests use SQLite entirely in memory and switch on `PRAGMA query_only` before inventory. Fixtures cover two identities, shared files, chat/POD/contact file chains, integer token-to-log references, notification/audit/geofence scope, invalid/missing selectors, count deduplication, redacted output, missing/changed schemas and relationship limits. They do not perform a restore or deletion. A separate private operator probe exercised the count-only helper in a MySQL read-only transaction for the explicitly authorized DEMO driver; this is not a synthetic erasure test or complete erasure proof.

## Use after identity/scope verification

An operator prepares a private JSON file containing exactly `{"user_uuid":"the verified account UUID"}`, owned by that operator and mode600. The file belongs outside public paths/Git. Copy the helper and selector into a **private** application-container directory, then invoke `php /private/tool/account-inventory.php /private/account-selector.json`. Do not put email, phone, password, OTP or tokens on the command line. Do not post selectors in chat or public logs.

The result prints counts, coverage and review gates only. A failure is generic and does not expose a PDO exception or SQL parameters. This helper has no execute/force/erase mode. A complete response still sets `execution_supported=false` and `complete_erasure_verified=false`; absence of candidates is not proof that free text/storage/backups contain no personal information.

Limit is 1,000 related IDs per branch and combined subject set; exceeding it fails without silently truncating. A larger production account needs a reviewed paginated inventory. Counts are a point-in-time observation; re-check scope and concurrent writes immediately before an approved deletion. No full-table free-text scan runs here.

Follow `doc/privacy/deletion-operations.md` for the rest of the verified manual process. Scope and approve irreversible operations separately. Do not blanket-prune restic snapshots or restore data; the owner excluded restore testing.

## Login-attempt password guard

`LoginAttempt.php` is a shipped-core overlay pinned by the production Dockerfile to upstream SHA256 `392c5c5f442fe7b99e73ba500aaabcc8adb5b056a996ba446199cca8c5701694`. The legacy `password` input remains accepted, but its Eloquent mutator always stores `NULL`, covering mass assignment, direct assignment and `forceFill`. JSON hiding alone did not protect database exports/backups. This does not sanitize historical rows or protect a new caller that bypasses Eloquent with a raw SQL write; neither is claimed.

`tests/login-password-persistence-test.php` runs against the real installed model and framework, with SQLite entirely in memory and application events disabled. It does not bootstrap the application, read production configuration, connect to MySQL or send notifications. The Docker build runs it after installing the overlay. A separate private network-disabled container run first reproduced plaintext persistence in the unpatched shipped model, then passed nine checks with the overlay. Full upstream Pest execution and production rollout are separate checks, not implied by that fixture result.
