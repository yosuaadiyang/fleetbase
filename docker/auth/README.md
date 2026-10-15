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
update. Unknown FleetOps permission mappings fail closed. This is not a claim of full role/tenant coverage
for every extension or API surface.

Authenticated permission denial returns 403 instead of 401. Anonymous or expired
credentials continue returning 401. The isolated HTTP contract includes positive
owner/viewer controls so denying every request cannot make the suite pass.

## Navigator driver boundary

`App\Support\ManagedDriverApiAccess` scopes both managed driver accounts and
staff-linked driver tokens to the token's owned, active driver profile. A driver
may read/update safe profile fields, register their device, send validated GPS,
toggle online status, and operate assigned orders through explicit delivery/POD
actions. Generic organization resource administration is denied. Additional
resource families (including chat, fuel and issue management) require dedicated
ownership contracts before they are enabled for driver sessions.

The embedded `NavigationAppLinker` credential only authorizes the driver login
and recovery bootstrap actions; it no longer inherits the creating admin's API
authority. No key or signed app-link is printed by QA.

The exact vendor OrderController is checked by SHA-256 at build time before
inserting a mandatory SQL assignment predicate for driver list queries. Its
shared query cache is bypassed: vendor cache keys include company and URL inputs
but not the actor or the server's assignment predicate. Sequential driver/staff
and staff/driver HTTP controls verify isolation in both directions. This avoids
mutable global model scopes in long-lived Octane workers.

Driver activity writes use the canonical next activity from the server's flow;
client events, completion flags and dispatch bypass inputs cannot override it.
Mandatory POD references must belong to the same order and company. POD entity
subjects are constrained to that order's payload. PNG signatures and PNG/JPEG/GIF
photos are bounded by byte length and dimensions; saved photo extensions and
MIME derive from image bytes rather than a supplied filename. The photo helper
also works on local storage where an S3 bucket is absent. Ten standalone image
cases supplement the real HTTP delivery/POD checks.

This gate currently proves a single pickup/dropoff transport workflow. Physical
Android background location, push, offline retry, multi-stop workflows, private
file delivery URLs, and optional Navigator resource families need separate
acceptance tests before being advertised.
