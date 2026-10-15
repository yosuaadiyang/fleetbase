# Public tracking response boundary

The pinned FleetOps internal `OrderController@lookup` action is intentionally
public so recipients can track a shipment by its tracking number. Its upstream
`OrderResource` also includes fields intended for the authenticated console.

`App\Http\Middleware\PublicOrderTracking` projects successful responses from
that exact action through an explicit allowlist, across API route prefixes.
Internal order actions retain their existing response contract. The allowlist
retains the tracking identity, public activity, shipment route, item grouping,
public driver map position, progress, and ETA needed by the existing tracker.
It omits internal order references, organization/customer identifiers, notes,
comments, attachments, financial records, contact information, access codes,
and arbitrary metadata. Unknown future resource fields are omitted as well.

Tracking numbers still grant access to the intended shipment tracking details,
including its route. This boundary does not implement tenant or role isolation
for authenticated endpoints. Driver locations are part of the existing public
tracking feature; separate privacy/product decisions about that feature remain
outside this projection.

Successful lookup responses disable caching. Invalid array/empty tracking query
values are rejected before the typed controller. Unknown ETA stays null and the
tracker displays a dash; an actual numeric zero remains a valid duration.

The unified `docker/brevo/Dockerfile` installs this middleware and runs 13
fixture-only checks against the real Laravel classes and Composer autoloader.
These checks require no database, email, network, or production records. The
build also retains the login validation, token-expiry, and Brevo transport tests.
