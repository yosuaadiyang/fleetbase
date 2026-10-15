#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/../.." && pwd)
cd "$root"
envfile=${1:?Synthetic QA env file required}
test -f "$envfile"
umask 077
compose=(docker compose --env-file "$envfile" -f docker/release-qa/compose.yml)
# Validate the resolved config in memory; never echo secrets into CI logs.
python3 - "$envfile" <<'PY'
import json,subprocess,sys
c=json.loads(subprocess.check_output(['docker','compose','--env-file',sys.argv[1],'-f','docker/release-qa/compose.yml','config','--format','json'],text=True))
a=c['services']['application'];e=a['environment']
assert c['name']=='contrust-release-qa'
assert e['DB_DATABASE']=='contrust_release_qa' and e['DB_USERNAME']=='contrust_qa'
assert e['APP_ENV']=='testing' and e['CONTRUST_RELEASE_QA']=='synthetic-only'
assert e['MAIL_MAILER']=='log' and e['BROADCAST_DRIVER']=='log' and not e['BREVO_API_KEY']
assert c['networks']['isolated']['internal'] is True
assert all(x['host_ip']=='127.0.0.1' for x in a['ports'])
assert all('env' not in x.get('source','') for x in a['volumes'] if x['type']=='bind')
assert all(x['image'].startswith('sha256:') for x in c['services'].values())
print('PASS isolated synthetic QA config: private network, loopback port, log mail, pinned images')
PY
if ! "${compose[@]}" up -d --pull never database cache application; then
 # Pinned QA MySQL logs contain startup diagnostics; no SQL general log or data.
 "${compose[@]}" logs --no-color --tail 60 database >&2
 exit 1
fi
"${compose[@]}" exec -T application php artisan migrate --force
"${compose[@]}" exec -T application php artisan fleetbase:create-permissions
"${compose[@]}" exec -T application php /opt/contrust-release-fixtures/seed.php
"${compose[@]}" exec -T application php /opt/contrust-release-fixtures/http-contract.php
