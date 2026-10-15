# Contrust offsite monitor

Runs on the separately owned backup server, not on the production VPS. Checks public console HTML, API health, routing, encrypted snapshot freshness (eight-hour limit), and free backup disk space. No authenticated customer data is requested.

Install `monitor.py` and `alerts.py` in `/usr/local/lib/contrust-monitor/`, and the service/timer under `/etc/systemd/system/`. State is root-only `/var/lib/contrust-monitor`. The timer runs every five minutes. Tests use mocked transport and do not send email: `python3 -m unittest discover -s scripts/monitor -p test_monitor.py`.

Email is optional. Without `/etc/contrust-monitor/alerts.json`, no notifications are sent. That private JSON uses `sender`, `recipient` and `api_key_file`; the key file contains the existing Brevo API key, root-only 0600, in a 0700 directory. Only enable it with the owner's specific authorization to copy the active credential and send test, incident and recovery notifications. Never store an active key in Git or print provider response bodies. Authorize the monitoring server IP with the provider while keeping other IP protections.

After two consecutive failed checks, an accepted incident alert is deduplicated; recovery generates one subsequent message. A test acceptance marker prevents accidental test resends. Do not remove it merely to repeat a test. HTTP acceptance is separate from inbox delivery: record the human confirmation as well. The deployed October 15 test was accepted and the owner confirmed receipt.

The service has a 60-second timeout, limited CPU/memory, restricted filesystem/address families, and can read repository metadata without exposing repository keys. Current checks do not certify authenticated WebSocket delivery, Android GPS, push notifications, database tenant isolation or a full customer workflow. A healthy result must not be represented as proof all product features work.
