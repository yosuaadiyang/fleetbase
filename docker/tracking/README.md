# Public tracking response boundary

The pinned FleetOps internal `OrderController@lookup` action is intentionally
public so recipients can track a shipment by its tracking number. Its upstream
`OrderResource` also includes fields intended for the authenticated console.

`App\Http\Middleware\PublicOrderTracking` projects successful responses from
that exact action through an explicit allowlist, across API route prefixes.
Internal order actions retain their existing response contract. The allowlist
retains the tracking identity, public activity, shipment route, item grouping,
public driver map position during an active delivery, progress, and ETA needed
by the existing tracker.
It omits internal order references, organization/customer identifiers, notes,
comments, attachments, financial records, contact information, access codes,
and arbitrary metadata. Unknown future resource fields are omitted as well.

Tracking numbers still grant access to the intended shipment tracking details,
including its route. Both `driver_assigned` and `tracker_data.driver` are null
unless the status is exactly `started` or `enroute`, the accepted v1 flow.
Created/dispatched, terminal, missing, malformed and custom/unknown statuses
do not disclose driver identity, online status, heading or current position.
The assignment flag remains accurate. A historical `started` flag cannot
re-enable disclosure after completion. New flow activities require an explicit
review before extending the allowlist. Authenticated internal order actions
retain their full contract. This does not revoke a recipient's tracking number
or remove the intended shipment route; those remain separate product decisions.

Successful lookup responses disable caching. Invalid array/empty tracking query
values are rejected before the typed controller. Unknown ETA stays null and the
tracker displays a dash; an actual numeric zero remains a valid duration.

The unified `docker/brevo/Dockerfile` installs this middleware and runs 26
fixture-only checks against the real Laravel classes and Composer autoloader.
These checks require no database, email, network, or production records. The
build also retains the login validation, token-expiry, and Brevo transport tests.
