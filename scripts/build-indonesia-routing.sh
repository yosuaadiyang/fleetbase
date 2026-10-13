#!/usr/bin/env bash
# Run on a preparation machine, not a production VPS under memory pressure.
set -euo pipefail
data_dir="${1:?Usage: build-indonesia-routing.sh /absolute/data/path}"
mkdir -p "$data_dir"
data_dir="$(cd "$data_dir" && pwd)"
repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
image='ghcr.io/project-osrm/osrm-backend@sha256:55dbfd47d984c2cacd64e32901b4321c86b5ec761438e69aa053dc78698a786f'
available_bytes="$(docker info --format '{{.MemTotal}}')"
if [ "$available_bytes" -lt 30000000000 ]; then
    printf 'Indonesia graph preparation needs a larger preparation machine: use a Docker host with about 32 GiB RAM. The 7 GiB extraction trial exhausted memory.\n' >&2
    exit 1
fi
url='https://download.geofabrik.de/asia/indonesia-261012.osm.pbf'
curl -fL --retry 3 "$url" -o "$data_dir/indonesia.osm.pbf"
curl -fL --retry 3 "$url.md5" -o "$data_dir/indonesia.osm.pbf.md5"
python3 - "$data_dir" <<'PY'
import hashlib, pathlib, sys
p = pathlib.Path(sys.argv[1]); h = hashlib.md5()
with (p / 'indonesia.osm.pbf').open('rb') as f:
    for block in iter(lambda: f.read(8 * 1024 * 1024), b''): h.update(block)
assert h.hexdigest() == (p / 'indonesia.osm.pbf.md5').read_text().split()[0], 'PBF checksum mismatch'
PY
docker build -t contrust-osmium-prep:20261013 "$repo_dir/docker/routing" -f "$repo_dir/docker/routing/Dockerfile.prep"
# Keep all members/referenced nodes and their tags. The car profile rejects ways
# without highway or route, so buildings/landuse need not enter its location cache.
docker run --rm --memory=4g --memory-swap=6g --cpus=4 -v "$data_dir:/data" contrust-osmium-prep:20261013 tags-filter /data/indonesia.osm.pbf nw/highway w/route w/railway r/type=restriction r/type=route -o /data/indonesia.routing.osm.pbf --output-header=osmosis_replication_timestamp!
for stage in extract partition customize; do
    args=("osrm-$stage" --threads 2 /data/indonesia.osrm)
    if [ "$stage" = extract ]; then args=(osrm-extract --threads 2 --profile /opt/car.lua /data/indonesia.routing.osm.pbf --output /data/indonesia.osrm); fi
    docker run --rm --memory=24g --memory-swap=28g --cpus=4 -v "$data_dir:/data" "$image" "${args[@]}"
done
docker run --rm --memory=1536m -v "$data_dir:/data:ro" "$image" osrm-routed --algorithm mld --mmap=true --threads 2 --trial=true /data/indonesia.osrm
printf 'Graph prepared; run representative route queries before activating the production overlay.\n'
