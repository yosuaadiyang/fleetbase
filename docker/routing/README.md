# Indonesia routing

The optional `docker-compose.routing.yml` overlay runs OSRM privately on the
existing Contrust Docker network. It publishes no host port. Frontend requests
must pass through Caddy and the rate-limited `routing-proxy`; backend workers use
`http://routing:5000` directly.

Prepare the graph with `bash scripts/build-indonesia-routing.sh /absolute/data/path`
on a separate preparation machine. The full, unfiltered Indonesia PBF exceeded a
6 GiB container memory limit during the October 2026 deployment preparation. The
script retains every highway/route/railway and restriction/route relation, all
referenced members/nodes and their tags, before running the standard car profile.
OSRM's car profile rejects ways with neither highway nor route. The filter does
not crop the country's geographic coverage. The filtered dataset reached graph
expansion but also exhausted a 7 GiB limit. Use a preparation Docker host with
about 32 GiB RAM; the script checks available RAM and caps processing at 24 GiB.
This is a preparation recommendation, not a measured successful peak: the graph
has not yet completed on a larger machine. The current 8 GiB VPS must not do this
preprocessing while serving production traffic.

The input is Geofabrik's Indonesia snapshot from 2026-10-12 (data timestamp
2026-10-12T20:21:35Z), verified against the publisher's MD5 checksum. It includes
East Timor. Data: © OpenStreetMap contributors, ODbL 1.0; see
https://www.openstreetmap.org/copyright and
https://download.geofabrik.de/asia/indonesia.html. Keep this attribution when
distributing the graph. OSRM is pinned to a multi-platform image digest reporting
v26.10.0; the same digest must prepare and serve the graph.

Do not activate the overlay until extraction, partitioning, customization, startup
trial and representative Indonesian queries pass. Copy the `indonesia.osrm.*`
files to a read-only data directory on the VPS and set `OSRM_DATA_DIR` to it. Runtime
uses memory mapping, a 1536 MiB memory limit, two threads and limits of 100 points
per route/table/match/trip. The public proxy allows GET/OPTIONS and a shared rate
of 10 requests/second with a burst of 20; errors are not cached. Monitor route
latency, memory, restarts and response codes before increasing these limits.

The profile is ordinary car/driving, matching the existing frontend's API. It does
not provide truck-specific routing or live traffic. Routes and ferry availability
depend on the OpenStreetMap snapshot. Updating the graph requires rerunning the
preparation pipeline on a new verified snapshot and swapping the tested data
directory; it is not an automated daily update.

When activated, add a Caddy `handle_path /routing/*` that proxies to
`routing-proxy:8080` on `api.contrust.website`, and set the public runtime
`OSRM_HOST` to `https://api.contrust.website/routing`. Add the overlay to the
existing `COMPOSE_FILE`, recreate only the routing/proxy and API/queue/scheduler
services as required, and refresh Laravel's configuration cache. Do not restart
the database to change routing.
