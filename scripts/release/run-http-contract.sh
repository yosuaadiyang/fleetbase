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
if ! "${compose[@]}" up -d --pull never database cache; then
 # Pinned QA MySQL logs contain startup diagnostics; no SQL general log or data.
 "${compose[@]}" logs --no-color --tail 60 database >&2
 exit 1
fi
# Initialize before starting HTTP. A fresh migration should not share the
# application container's memory allowance with a second PHP server process.
initialize_compose=("${compose[@]}" -f docker/release-qa/compose.initialize.yml)
run_initialize() {
 local status=0 name="contrust-release-qa-initialize-$$"
 "${initialize_compose[@]}" run --name "$name" -T application php artisan "$@" || status=$?
 # Keep the stopped CLI container until its actual exit/oom state is recorded.
 # No environment, tokens, command arguments, or application logs are printed.
 docker inspect --format '{"initializer_exit":{{.State.ExitCode}},"oom_killed":{{.State.OOMKilled}},"started_at":"{{.State.StartedAt}}","finished_at":"{{.State.FinishedAt}}"}' "$name" || true
 docker rm "$name" >/dev/null
 if [ "$status" -ne 0 ]; then
  free -m
  df -h /
  return "$status"
 fi
}
run_initialize migrate --force
run_initialize fleetbase:create-permissions
"${compose[@]}" up -d --wait --wait-timeout 120 --pull never application
report_file=$(mktemp)
trap 'rm -f "$report_file"' EXIT
run_fixture() {
 local name=$1 kind=$2 status=0
 local fixture_args=()
 if [ "$#" -gt 2 ]; then fixture_args+=("$3"); fi
 "${compose[@]}" exec -T application php "/opt/contrust-release-fixtures/$name" "${fixture_args[@]}" > "$report_file" || status=$?
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
run_fixture organization-chat-contract.php organization-chat
run_fixture chat-pagination-contract.php chat-pagination
run_fixture push-device-contract.php push-devices
run_fixture internal-chat-contract.php internal-chat
run_fixture web-chat-history-contract.php web-chat-history
run_fixture chat-safety-contract.php chat-safety

run_fixture driver-report-contract.php driver-reports

run_fixture diagnostic-access-contract.php diagnostic-access

run_fixture dashboard-metrics-contract.php dashboard-metrics

run_fixture resource-crud-contract.php resource-crud

# Expanded reporting is exercised only after all legacy contracts passed. This
# stack was validated above as synthetic-only with internal networking/log mail.
run_fixture operational-content-contract.php operational-content-prepare prepare
operational_override=$(mktemp)
trap 'rm -f "$report_file" "$operational_override"' EXIT
cat > "$operational_override" <<'JSON'
{"services":{"application":{"environment":{"CONTRUST_CHAT_SAFETY_ENABLED":"true","CONTRUST_OPERATIONAL_REPORTS_ENABLED":"true"}}}}
JSON
python3 - "$envfile" "$operational_override" <<'PYCONFIG'
import json,subprocess,sys
base=['docker','compose','--env-file',sys.argv[1],'-f','docker/release-qa/compose.yml']
old=json.loads(subprocess.check_output(base+['config','--format','json'],text=True))
new=json.loads(subprocess.check_output(base+['-f',sys.argv[2],'config','--format','json'],text=True))
assert old['name']=='contrust-release-qa' and old['networks']['isolated']['internal']
e=old['services']['application']['environment']
assert e['DB_DATABASE']=='contrust_release_qa' and e['APP_ENV']=='testing' and e['MAIL_MAILER']==e['BROADCAST_DRIVER']=='log' and not e['BREVO_API_KEY']
expected=dict(old,services=dict(old['services']))
app=old['services']['application']
expected['services']['application']=dict(app,environment=dict(app['environment'],CONTRUST_CHAT_SAFETY_ENABLED='true',CONTRUST_OPERATIONAL_REPORTS_ENABLED='true'))
assert new==expected
print('PASS operational feature enabled only in guarded synthetic QA config')
PYCONFIG
compose+=(-f "$operational_override")
"${compose[@]}" up -d --force-recreate --no-deps --no-build --pull never --wait --wait-timeout 120 application
run_fixture operational-content-contract.php operational-content
