# Contrust encrypted backup

Implementation uses restic with an encrypted SFTP repository on the operator's
separate server. The backup SSH account is dedicated to this repository, has a
root-owned authorized_keys file, forced internal-sftp, and a root-owned chroot.
It cannot open a shell or forward ports. Production data is never copied to QA.

Backup covers a consistent transactional MySQL dump, application files/POD,
application/root environment, runtime configuration, proxy configuration and
image/source metadata. Routing graph can be rebuilt and is excluded. Snapshots
on the original VPS are not a substitute for this offsite repository.

The timer runs at 00:30, 06:30, 12:30 and 18:30 UTC with up to 15 minutes of
jitter (07:30, 13:30, 19:30 and 01:30 WIB). This targets a six-hour backup
interval; it is not a guaranteed RPO. Check the last-success timestamp and
service exit status. Transactional database consistency does not make uploads
atomic with the database dump. The SFTP repository is encrypted but not
immutable: a compromised source key could delete it.

Repository passwords and SSH private keys are generated on the source server,
kept root-only under /etc/contrust-backup, and must never enter Git, CI logs or
public source archives. Keep a separate private recovery copy of the repository
password; losing both that password and the source server makes recovery
impossible. Recovery keys must not be stored alongside the remote repository.

Initial rollout must perform repository integrity checks and a restore drill to
an isolated MySQL server. A successful snapshot alone is not a restore test.
Retention/delete policies are deliberately not enabled until recovery and disk
use are reviewed. Monitor age of last successful backup and backup failures.
