# Private chat safety candidate

This is an implementation candidate, disabled by default. No production flag,
policy acceptance, report, block or new moderator access has been activated.
The current Android production versionCode 1 has no controls for this API and
must remain immutable. A later version and matching web controls are required.

`CONTRUST_CHAT_SAFETY_ENABLED=true` enables both the endpoints and contribution
checks. Keep it unset until the owner reviews the published policy, report
recipient, bounded review access, actual UI and deployment evidence. An enabled
backend with old clients would intentionally reject unconsented chat writes.

Endpoints use `/int/v1/contrust/chat-safety`:

- `GET /`: current policy version/URL, own consent, own block list (100 per page,
  `blocked_after` cursor), moderation availability and the shared-group boundary.
- `POST /consent`: exactly current `policy_version` and boolean `accepted: true`.
  Clients must display the policy and record the user's own decision.
- `POST /blocks`, `DELETE /blocks`: one `user` public ID in the actor's company.
- `POST /reports`: UUID `request_id`, `type` (`user` or `message`), public `target`,
  allowlisted `reason`, optional string `details` (2,000 characters), and explicit
  boolean `share_context: true`. The confirmation must identify the organization
  owner as recipient and show the selected context before transmission.
- `GET /reports`: owner-only selected report contexts, 25 per page, `before` UUID.
- `PATCH /reports/{id}`: bounded `status` and `resolution`, recorded reviewer/time.
  This records a review outcome; it does not silently delete messages or suspend
  accounts. Actual operational follow-up and its permissions require review.

Only unexpired, active-user PATs are accepted. Embedded bootstrap keys cannot use
the API. Fresh organization membership is checked even for driver profiles. The
PAT needs `chat:read`/`chat:write` or the ordinary wildcard permission; review also
requires `chat:moderate` and the organization owner using a staff session. An
ordinary admin is not granted blanket access to private rooms.

Consent, blocks and reports use separate private tables. A report stores only the
selected message (when applicable), target public name/ID and the explicit form
details, encrypted with APP_KEY. It does not copy the surrounding feed, contacts,
attachments or coordinates. Responses disable shared caching. Reports use an
actor/company/request unique key; changed input with the same key returns 409.

Direct sends and new invitations enforce blocks in both public and web chat.
Existing shared operational groups remain visible; this limit must be explained
before blocking. A company row lock spans each safety mutation and actual chat
write so concurrent blocking and contributions serialize. The low-volume pilot
is the intended load; no large-company throughput claim is made. Read history,
leave/delete controls, GPS and logout do not require new communication consent.

The account-erasure inventory counts consent, either side of blocks and the
reporter/target/reviewer relationships. These counts do not authorize deletion or
prove full erasure of shared information or encrypted backup history.

Tests: `tests/safety-input-test.php` has no DB/network. The real HTTP/MySQL fixture
`docker/release-qa/fixtures/chat-safety-contract.php` refuses production databases
and non-log notification transports. It uses a temporary loopback HTTP process
inside the isolated QA container to enable the feature without changing the
default-disabled QA server. CI must validate its structured result, not just exit
status. Feature-on runtime results, web/native UI, policy review, moderation
operations and owner activation approval remain separate acceptance gates.
