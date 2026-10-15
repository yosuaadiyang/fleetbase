#!/usr/bin/env bash
set -euo pipefail
pubkey=${1:?Public key file required}
source_ip=${2:?Source server IP required}
python3 - "$source_ip" <<'PYIP'
import ipaddress,sys
ipaddress.ip_address(sys.argv[1])
PYIP
test "$(id -u)" = 0
test -s "$pubkey"
if id contrustbackup >/dev/null 2>&1; then echo 'Refusing to change an existing backup account' >&2; exit 1; fi
test ! -e /var/lib/contrust-backup
ssh-keygen -lf "$pubkey" >/dev/null
install -d -m 755 /var/lib/contrust-backup
useradd --no-create-home --home-dir / --shell /usr/sbin/nologin contrustbackup
install -d -o contrustbackup -g contrustbackup -m 700 /var/lib/contrust-backup/repository
install -d -o root -g root -m 755 /etc/ssh/authorized_keys
{ printf 'from="%s",restrict ' "$source_ip"; cat "$pubkey"; } > /etc/ssh/authorized_keys/contrustbackup
chmod 644 /etc/ssh/authorized_keys/contrustbackup
cat > /etc/ssh/sshd_config.d/80-contrust-backup.conf <<'CONF'
Match User contrustbackup
    AuthorizedKeysFile /etc/ssh/authorized_keys/contrustbackup
    ChrootDirectory /var/lib/contrust-backup
    ForceCommand internal-sftp -d /repository
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    DisableForwarding yes
    PermitTTY no
Match all
CONF
if ! /usr/sbin/sshd -t; then
 mv /etc/ssh/sshd_config.d/80-contrust-backup.conf /etc/ssh/80-contrust-backup.disabled
 echo 'SSH config rejected; new match block disabled' >&2
 exit 1
fi
systemctl reload ssh
printf 'Dedicated chrooted SFTP backup receiver configured\n'
