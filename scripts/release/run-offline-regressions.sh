#!/usr/bin/env bash
set -euo pipefail
image=${1:?Application image required}
root=$(cd "$(dirname "$0")/../.." && pwd)
docker image inspect "$image" --format '{{.Id}}'
run_test() {
 local group=$1 file=$2
 docker run --rm --network none --read-only --tmpfs /tmp:rw,noexec,nosuid,size=32m \
  --entrypoint php -v "$root/docker/$group/tests:/opt/contrust-tests:ro" \
  "$image" "/opt/contrust-tests/$file"
}
run_test auth request-validation-test.php
run_test auth token-login-test.php
run_test auth api-token-lifetime-test.php
run_test auth proof-image-test.php
run_test auth sms-log-privacy-test.php
run_test brevo brevo-transport-test.php
run_test tracking public-tracking-test.php
# The standalone helper test resolves ../api/OSRM.php relative to its test dir.
docker run --rm --network none --read-only --tmpfs /tmp:rw,noexec,nosuid,size=32m \
 --entrypoint php -v "$root/docker/routing:/opt/contrust-routing:ro" \
 "$image" /opt/contrust-routing/tests/osrm-helper-test.php
run_test routing real-vendor-test.php

run_test socket publisher-test.php
run_test notifications channel-policy-test.php
