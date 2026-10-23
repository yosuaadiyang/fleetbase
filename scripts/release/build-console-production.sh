#!/usr/bin/env bash
set -euo pipefail
: "${SOURCE_CODE_URL:?Set SOURCE_CODE_URL to the exact source commit}"
release_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# Public endpoints only. Credentials never belong in a console bundle.
export API_HOST=https://api.contrust.website API_SECURE=true API_NAMESPACE=int/v1
export OSRM_HOST=https://api.contrust.website/routing
export SOCKETCLUSTER_HOST=api.contrust.website SOCKETCLUSTER_SECURE=true SOCKETCLUSTER_PORT=443 SOCKETCLUSTER_PATH=/socketcluster/
export DISABLE_RUNTIME_CONFIG=true
cd "$release_root/console"
./node_modules/.bin/ember build --environment=production
node "$release_root/scripts/release/check-console-build-config.cjs" "$release_root/console/dist"
python3 "$release_root/scripts/release/check-frontend-artifacts.py" "$release_root/console/dist"
