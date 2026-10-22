# Account data inventory (operator preflight)

This counts potential account relationships in **one current database**, without deleting, updating or reading record contents. It does not replace a schema/content review, extend any API access, create a deletion endpoint, or verify complete erasure. Do not put this directory in an HTTP document root.

The owner chose on 22 October 2026: **retain data until the account is deleted**, including location history, order/POD/chat/reports and backup copies. There is no additional fixed-day expiry while an account exists. This policy is not approval to delete a live account or all historical backup snapshots.

The helper follows user → membership/driver/contact, participant → messages/attachments, driver/contact → orders, and order → proof/file relationships. It counts candidate rows only. A related row may contain other people's data; its presence is **not authorization to delete it**. Unknown schema/missing tables remain explicit gaps. Polymorphic IDs require operator type review. Embedded names/emails/coordinates, raw payloads, files, logs, other schemas and providers require separate inventory.

## Tests

`php docker/privacy/tests/account-inventory-test.php`

Tests use SQLite entirely in memory and switch on `PRAGMA query_only` before inventory. Fixtures cover two identities, shared files, chat/POD chains, invalid/missing selectors, count deduplication, redacted output, missing/changed schemas and relationship limits. They do not exercise the production MySQL schema or perform a restore.

## Use after identity/scope verification

An operator prepares a private JSON file containing exactly `{"user_uuid":"the verified account UUID"}`, owned by that operator and mode600. The file belongs outside public paths/Git. Copy the helper and selector into a **private** application-container directory, then invoke `php /private/tool/account-inventory.php /private/account-selector.json`. Do not put email, phone, password, OTP or tokens on the command line. Do not post selectors in chat or public logs.

The result prints counts, coverage and review gates only. A failure is generic and does not expose a PDO exception or SQL parameters. This helper has no execute/force/erase mode. A complete response still sets `execution_supported=false` and `complete_erasure_verified=false`; absence of candidates is not proof that free text/storage/backups contain no personal information.

Limit is 1,000 related IDs per branch and combined subject set; exceeding it fails without silently truncating. A larger production account needs a reviewed paginated inventory. Counts are a point-in-time observation; re-check scope and concurrent writes immediately before an approved deletion. No full-table free-text scan runs here.

Follow `doc/privacy/deletion-operations.md` for the rest of the verified manual process. Scope and approve irreversible operations separately. Do not blanket-prune restic snapshots or restore data; the owner excluded restore testing.
