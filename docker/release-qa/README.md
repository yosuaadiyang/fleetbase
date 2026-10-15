# Synthetic release QA

This Compose project does not extend the production stack, load production env,
mount production storage, or connect to production networks. Its network is
internal-only and its HTTP port binds loopback. MySQL/Redis/application have
resource limits. Email/broadcast are log-only, queue work is not started, provider
URLs cannot resolve externally, and application/encryption/database credentials
are independently generated. Fixture scripts refuse every database except
contrust_release_qa with the explicit synthetic-only environment marker.

The HTTP test server uses one PHP CLI process to reduce RAM. It exercises the
real Laravel routes/middleware/controllers and MySQL, but is not suitable for
claiming production performance/Octane concurrency results.

On a machine with Docker and the required images already available:

```sh
python3 scripts/release/prepare-qa-env.py --output qa-release.env --api-image contrust-api:release-qa
bash scripts/release/run-http-contract.sh qa-release.env
```

The environment file is mode 0600 and ignored by Git. Fixture credentials/tokens
remain inside the QA storage volume and must not be printed or published. Two
organizations and eight actors are seeded idempotently. Owners are regular staff
with the Administrator organization role, not platform-superadmin user types.
Viewer and dispatcher have explicit permissions. Driver identities are separate.
No verification bypass is enabled. The extra staff-linked driver tests that
being a staff member cannot widen a driver app token's scope. The HTTP suite
also checks expired tokens, inactive drivers, embedded bootstrap credentials,
cross-driver GPS/profile/order access and query-cache isolation. Order creation
uses a synthetic transport configuration; no dispatch workers run in QA.

The gate fails when required HTTP scenarios fail. It never skips tests because a
Postman/provider/GitHub secret is missing. The public-repository workflow uses
standard runners and no artifact storage or external provider credentials.

The companion frontend job requires named regression suites and a TAP summary
with passing tests and zero skipped/todo/failed tests before building production.
This is targeted release coverage, not evidence that every upstream scaffold
unit test or every business transaction has passed.
