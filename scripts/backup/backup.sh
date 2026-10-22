#!/usr/bin/env bash
set -euo pipefail
umask 077
config=/etc/contrust-backup
state=/var/lib/contrust-backup
source "$config/repository.env"
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE RESTIC_CACHE_DIR
mkdir -p "$state/staging/database" "$state/staging/config" "$state/staging/source" "$state/status"
exec 9> "$state/backup.lock"
flock -n 9 || { echo 'Another Contrust backup is running' >&2; exit 1; }
trap 'status=$?; if [ "$status" -ne 0 ]; then date -u +%FT%TZ > "$state/status/last-failure"; fi' EXIT
cd /opt/contrust
# Credentials are consumed inside the DB container, never placed on host argv.
# Only this database container is read; every schema name is filtered first.
docker exec contrust-database-1 sh -c '
 set -eu
 export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-}"
 mysql -u root -N -e "SHOW DATABASES" | grep -E "^fleetbase(_[a-z0-9_]+)?$" > /tmp/contrust-backup-databases
 test -s /tmp/contrust-backup-databases
 mysqldump -u root --single-transaction --quick --routines --triggers --events --set-gtid-purged=OFF --no-tablespaces --databases $(cat /tmp/contrust-backup-databases)
 rm -f /tmp/contrust-backup-databases
' > "$state/staging/database/contrust.sql.partial"
test -s "$state/staging/database/contrust.sql.partial"
mv "$state/staging/database/contrust.sql.partial" "$state/staging/database/contrust.sql"
# Config paths are explicit; do not include other applications or SSH credentials.
python3 - "$state/staging/config" <<'PY'
import pathlib,shutil,sys,json,subprocess
base=pathlib.Path('/opt/contrust');dest=pathlib.Path(sys.argv[1])
for name in [
 '.env','api/.env','console/fleetbase.config.json',
 'docker-compose.yml','docker-compose.override.yml','docker-compose.prod.yml',
 'docker-compose.routing.yml','docker-compose.socket-security.yml',
 'docker/caddy/Caddyfile','docker/httpd/vhost.conf',
 # Active runtime overlays and the explicitly approved production FCM secret.
 'runtime/compose-push.yml','runtime/compose-public-policies.yml',
 'secrets/firebase/contrust-fcm-sender.json',
 # These exact source/config files recreate the public-policy mounts.
 'console/nginx.conf','console/public/privacy/index.html',
 'console/public/terms/index.html','console/public/delete-account/index.html',
 'console/public/policy-assets/policies.css',
]:
 p=base/name
 if p.is_file():
  q=dest/name;q.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,q)
socket_env=pathlib.Path('/etc/contrust-socket/publisher.env')
if socket_env.is_file():
 q=dest/'socket'/'publisher.env';q.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(socket_env,q)
images={s:subprocess.check_output(['docker','inspect','--format','{{.Image}}','contrust-'+s+'-1'],text=True).strip() for s in ['application','queue','scheduler','console','database','cache','socket']}
(dest/'images.json').write_text(json.dumps(images,indent=2)+'\n')
PY
# Keep the source corresponding to the running console/API release for recovery.
# This public archive contains source only; environment secrets stay encrypted.
docker cp contrust-console-1:/usr/share/nginx/html/source/contrust-audit-20261013.tar.gz \
 "$state/staging/source/contrust-source.tar.gz.partial"
test -s "$state/staging/source/contrust-source.tar.gz.partial"
tar -tzf "$state/staging/source/contrust-source.tar.gz.partial" > /dev/null
mv "$state/staging/source/contrust-source.tar.gz.partial" "$state/staging/source/contrust-source.tar.gz"
(cd "$state/staging/source" && sha256sum contrust-source.tar.gz > SHA256SUMS)
restic -o sftp.command="ssh -F $config/ssh_config contrust-backup -s sftp" \
 backup --host contrust-production --tag contrust \
 "$state/staging" /opt/contrust/api/storage/app /opt/contrust/api/config/fleetbase.php \
 /opt/contrust/api/resources/views/vendor --json > "$state/status/last-backup.json.partial"
mv "$state/status/last-backup.json.partial" "$state/status/last-backup.json"
date -u +%FT%TZ > "$state/status/last-success"
# Remove the plaintext SQL only after a successful encrypted offsite snapshot.
rm -f "$state/staging/database/contrust.sql"
echo 'Contrust offsite backup completed'
