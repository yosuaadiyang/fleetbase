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
run_test auth account-session-test.php
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

run_test chat pagination-input-test.php
run_test chat safety-input-test.php

# New report selection and account inventory run on synthetic in-memory data.
# Mount the candidate source explicitly; no production environment or storage.
docker run --rm --network none --read-only --tmpfs /tmp:rw,noexec,nosuid,size=32m \
 --entrypoint php -e CONTRUST_OPERATIONAL_CONTEXT_SOURCE=/opt/OperationalReportContext.php \
 -v "$root/api/app/Support/OperationalReportContext.php:/opt/OperationalReportContext.php:ro" \
 -v "$root/docker/chat/tests:/opt/contrust-context-tests:ro" \
 "$image" /opt/contrust-context-tests/operational-context-test.php
docker run --rm --network none --read-only --tmpfs /tmp:rw,noexec,nosuid,size=32m \
 --entrypoint php -e CONTRUST_OPERATIONAL_PREVIEW_SOURCE=/opt/OperationalReportPreview.php \
 -v "$root/api/app/Support/OperationalReportPreview.php:/opt/OperationalReportPreview.php:ro" \
 -v "$root/docker/chat/tests:/opt/contrust-context-tests:ro" \
 "$image" /opt/contrust-context-tests/operational-preview-test.php
docker run --rm --network none --read-only --tmpfs /tmp:rw,noexec,nosuid,size=32m \
 --entrypoint php -e CONTRUST_TEST_SAFETY_SOURCE_DIR=/opt/contrust-support \
 -v "$root/api/app/Support:/opt/contrust-support:ro" \
 -v "$root/docker/chat/tests:/opt/contrust-context-tests:ro" \
 "$image" /opt/contrust-context-tests/operational-input-test.php
docker run --rm --network none --read-only --tmpfs /tmp:rw,noexec,nosuid,size=32m \
 --entrypoint php -v "$root/docker/privacy:/opt/contrust-privacy:ro" \
 "$image" /opt/contrust-privacy/tests/account-inventory-test.php
