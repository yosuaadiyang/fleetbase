#!/usr/bin/env bash
# Run only on the disposable CI runner. No registry pushes or uploaded artifacts.
set -euo pipefail

build_dir=$(mktemp -d "${RUNNER_TEMP:-/tmp}/contrust-api-build.XXXXXXXX")
builder="contrust-api-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}"
monitor_pid=''
builder_created=false
cleanup() {
  result=$?
  trap - EXIT INT TERM
  if [ -n "$monitor_pid" ]; then
    kill "$monitor_pid" 2>/dev/null || true
    wait "$monitor_pid" 2>/dev/null || true
  fi
  if [ "$builder_created" = true ]; then
    docker buildx rm --force "$builder" >/dev/null 2>&1 || true
  fi
  rm -rf -- "$build_dir"
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Whitelisted public engine metadata only; never dump environment or config.
docker version --format '{{json .Client.Version}} {{json .Server.Version}}'
docker info --format 'storage_driver={{.Driver}}'
docker buildx version
(
  while true; do
    date -u '+build_capacity_utc=%Y-%m-%dT%H:%M:%SZ'
    free -m
    df -Pm / /mnt /var/lib/docker
    if [ -f /sys/fs/cgroup/memory.events ]; then
      cat /sys/fs/cgroup/memory.events
    fi
    sleep 15
  done
) &
monitor_pid=$!

cat > "$build_dir/buildkitd.toml" <<'TOML'
[worker.oci]
  max-parallelism = 2
TOML
docker buildx create --name "$builder" --driver docker-container \
  --buildkitd-config "$build_dir/buildkitd.toml"
builder_created=true

# Export an uncompressed Docker archive, then explicitly load it. This avoids
# the default daemon exporter path that repeatedly stalled on hosted runners.
# Preserve all Dockerfile guards and tests; no fallback may bypass a failure.
docker buildx build --builder "$builder" --progress plain \
  --provenance=false --sbom=false \
  --output "type=docker,compression=uncompressed,dest=$build_dir/api.tar" \
  -f docker/brevo/Dockerfile -t contrust-api:release-qa .
docker load --input "$build_dir/api.tar"
docker image inspect --format 'loaded_api_image={{.Id}}' contrust-api:release-qa
