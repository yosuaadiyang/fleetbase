# Authentication validation and token login patch

The request overrides throw HttpResponseException on validation failure, so
Laravel returns JSON errors/422 before reaching a controller. String input
validation rejects arrays and integers. Login requires a password unless an
authToken is supplied: the console authenticates twice, first with a password,
then with the returned token only. Requiring the password at both steps breaks
successful password/reset login.

The controller override retains token-owner and console-role checks, rejects
explicit/global token expiration, and returns generic invalid_credentials/401
when an invalid token has no password. A valid password remains a fallback.

Production builds use docker/brevo/Dockerfile from repository root, as configured
in docker-compose.routing.yml. This pinned-base build includes routing, all
request/controller fixes, and Brevo together; it does not depend on an
intermediate local image tag. docker/auth/Dockerfile is only the standalone
request-validation diagnostic build, not the complete production image.

The unified build runs 15 real Laravel validation lifecycle cases, 10 real
controller/model login cases against isolated in-memory SQLite, and mocked
Brevo transport tests. Test fixture container/schema definitions derive from
the core-api tests at the repository's pinned submodule commit. No production
database, network, email or credentials are accessed by build tests. The
presence verifier for request-only tests is synthetic. Human login remains
necessary to verify the actual browser session and account.

Deploy with environment/volumes preserved, graceful queue/scheduler restarts,
API health and routing checks, and a matching published source archive.

## Release authorization checks — 2026-10-15

The consumable API middleware now applies both explicit Sanctum token expiry and
the configured global lifetime. Previously it found the token by hash without
applying these lifetime checks, even though console token login rejected expiry.
Eight real Sanctum/SQLite-memory checks cover fresh, explicit/global expiry,
unknown/revoked tokens, disabled global expiry and invalid lifetime configuration.

Staff IAM permissions also apply when a staff token calls FleetOps consumable
API controllers. These controllers do not implement the console resource metadata
interface, so permission resolution maps their server-side controller resource
and action to the existing fleet-ops IAM namespace. Standard CRUD and registered
schema actions are respected; other reads require view and other writes require
update. Managed driver/customer profiles retain their existing controller scopes
and require separate scope tests. This is not a claim of full role/tenant coverage
for every extension or API surface.

Authenticated permission denial returns 403 instead of 401. Anonymous or expired
credentials continue returning 401. The isolated HTTP contract includes positive
owner/viewer controls so denying every request cannot make the suite pass.
