# Contrust account/data request handling — prepared 21 October 2026

Public operator and contact explicitly supplied by the owner: **YAYGHB**, **ptgayahidupbersama@gmail.com**. The public pages are under `console/public/privacy`, `terms`, and `delete-account`. They require no login or JavaScript. The email action opens a user-controlled draft, never sends an automatic message or claims the request is already processed.

## Release status

These pages describe current behavior, including the absence of a verified universal expiry schedule. They do **not** establish a complete deletion service or a final retention policy. Owner retention decisions, end-to-end handling, provider/backup handling and public route checks remain gates. Do not mark the Play Data safety deletion/retention declarations complete from the mere existence of a link. No live account or record was deleted during this audit.

The native account Data Protection section now links to `/delete-account` without attaching account identifiers, tokens, or query strings. This opens instructions for a manual request; it does not delete data from the phone or server.

## Why the existing CLI is insufficient on its own

`packages/core-api/src/Services/UserDeletionService.php` discovers `users` foreign-key references and `user_uuid` columns, deletes identity-bound rows, clears nullable business references and deletes the user in a transaction. It also unassigns orders before deleting linked drivers/contacts. It is a useful schema cleanup primitive, **not** a verified end-to-end erasure workflow:

- Driver-linked position history is not necessarily keyed directly to the user.
- Clearing an author/customer reference does not redact identifying content embedded in order payloads, messages, comments, reports or audit metadata.
- Deleting a database file row does not prove that the corresponding storage object, derivative, or cache has been removed.
- Personal access tokens can use polymorphic ownership rather than a `users` foreign key.
- Email/push providers and encrypted backup snapshots require separate handling.
- Preserving shared business records requires a documented reason and reviewed redaction, not an automatic assumption that every record may remain unchanged.

Do not execute `fleetbase:user-delete --execute` as a substitute for this inventory. Its default preview does not mutate data, but can print private identity details; keep previews in private operator storage, never public CI logs or chat.

## Manual handling checklist

1. Receive the request in the owner-authorized support mailbox. Do not require an app login, payment, password or OTP. Verify ownership using a safe established account channel; handle loss of email access separately. Do not disclose whether arbitrary third-party accounts exist.
2. Establish whether this is a full account request or a category-specific request. Privately identify user, company memberships, driver/contact identities and storage references. Limit the lookup to the verified requester. An account can belong to multiple organizations.
3. Record the scope, received time, verification outcome and responsible operator in restricted storage. Confirm the expected handling period with the requester. The owner has not yet adopted a general SLA; do not invent one.
4. Preview affected sessions/tokens, profile/contact records, driver positions and metadata, push registrations, chat/message/comment content, reports, uploaded originals/derivatives, POD and order payloads. Review actual database schema and storage paths. Separate other users' data and shared organization records.
5. For each retained item document the specific legitimate purpose, minimum retained fields, lawful basis where applicable, and end date. Owner agreement to a retention period does not itself establish a legal basis. Do not retain an identifying payload merely because its user foreign key was nulled.
6. Prepare a scoped, reviewable removal/redaction plan and obtain authorization before irreversible live changes. Stop access/revoke sessions when appropriate, then apply the plan transactionally where possible. Do not fabricate POD, completion events, or finance actions. Verify originals, thumbnails/caches and identifying metadata separately.
7. Address relevant provider records through the provider's supported controls. Do not send new emails/push notifications merely to test deletion. Record any provider retention restriction and its expected expiry.
8. Inventory encrypted snapshots that can contain the data. Define expiry and removal handling before calling the request complete. Never enable an unapproved `restic forget/prune`, change backup retention silently, or restore production data to test this workflow. The owner explicitly excludes restore testing.
9. Verify the result with a private category checklist. Give the requester the completed categories and the reason/duration for any exception. Do not equate disabling an account, unlinking a driver, or deleting a database row with complete account/data erasure.

## Pending owner retention decision

No general duration has been approved for driver location history, order/POD/chat/report content, or all offsite snapshots. A question is pending. Do not add destructive scheduled cleanup until the owner selects a policy and the actual data relationships, legal obligations, historical snapshots and safety checks are covered. GPS UI queue size/backoff limits are not server retention periods.

## Technical checks required before deployment

- `/privacy`, `/terms`, `/delete-account` and their CSS return real static content over HTTPS without authentication; missing files return 404 rather than the SPA shell.
- Same pages remain readable at mobile width, with keyboard focus and no external scripts, fonts, trackers or customer data.
- Mail link points only to the approved public email with a fixed subject, no user identifiers or credentials. Keep a copyable address for users without a local mail handler.
- Account menu opens the external deletion resource; failures produce visible feedback. The native candidate must be rebuilt and its corresponding source archive regenerated after this source change.

Policy references checked 21 October 2026:
- [Google Play account deletion requirements](https://support.google.com/googleplay/android-developer/answer/13327111?hl=en)
- [Google Play User Data](https://support.google.com/googleplay/android-developer/answer/10144311?hl=en)
- [Location/background disclosure](https://support.google.com/googleplay/android-developer/answer/9799150?hl=en)
