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
report_file=$(mktemp)
trap 'rm -f "$report_file"' EXIT
run_fixture() {
 local name=$1 kind=$2 status=0
 "${compose[@]}" exec -T application php "/opt/contrust-release-fixtures/$name" > "$report_file" || status=$?
 python3 scripts/release/check-qa-results.py "$kind" "$report_file"
 test "$status" -eq 0
}
run_fixture seed.php seed
run_fixture http-contract.php delivery
run_fixture driver-otp-contract.php otp
run_fixture place-input-contract.php place

run_fixture socket-contract.php socket

socket_image=$(python3 - "$envfile" <<'PYIMAGE'
import pathlib,sys
values=dict(l.split('=',1) for l in pathlib.Path(sys.argv[1]).read_text().splitlines() if '=' in l)
image=values['CONTRUST_QA_SOCKET_IMAGE']
assert image.startswith('sha256:') and len(image)==71
print(image)
PYIMAGE
)
volume=$(docker inspect contrust-release-qa-application-1 --format '{{range .Mounts}}{{if eq .Destination "/fleetbase/api/storage"}}{{.Name}}{{end}}{{end}}')
docker run --rm --network contrust-release-qa_isolated --memory 192m --cpus 0.5 \
 -v "$volume:/qa:ro" -v "$root/docker/socket/tests/transport.js:/usr/src/transport.js:ro" \
 --entrypoint node "$socket_image" /usr/src/transport.js > "$report_file"
python3 scripts/release/check-qa-results.py socket-transport "$report_file"
run_fixture proof-file-contract.php proof-files

run_fixture driver-comment-contract.php driver-comments
