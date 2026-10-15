#!/usr/bin/env python3
"""Public health checks and private status, with explicitly configured incident alerts."""
import datetime, json, os, pathlib, shutil, time, urllib.request
import alerts

STATE = pathlib.Path('/var/lib/contrust-monitor')
REPOSITORY = pathlib.Path('/var/lib/contrust-backup/repository')
ENDPOINTS = (
    ('console', 'https://contrust.website/', 'html'),
    ('api', 'https://api.contrust.website/health', 'health'),
    ('routing', 'https://api.contrust.website/routing/route/v1/driving/106.8272,-6.1754;106.8456,-6.2088?overview=false', 'route'),
)

def check_endpoint(name, url, kind, opener=urllib.request.urlopen):
    start = time.monotonic()
    result = {'name': name, 'passed': False}
    try:
        request = urllib.request.Request(url, headers={'User-Agent': 'ContrustOffsiteHealth/1.0', 'Accept': 'application/json' if kind != 'html' else 'text/html'})
        with opener(request, timeout=8) as response:
            body = response.read(65537)
            result['http'] = response.status
            if response.status != 200 or len(body) > 65536:
                raise ValueError('Unexpected response status or size')
            if kind == 'html':
                result['passed'] = b'<html' in body.lower() and b'contrust' in body.lower()
            else:
                payload = json.loads(body)
                result['passed'] = isinstance(payload, dict) and payload.get('status' if kind == 'health' else 'code') == ('ok' if kind == 'health' else 'Ok')
    except Exception as error:
        result['error_type'] = type(error).__name__
        if hasattr(error, 'code'):
            result['http'] = error.code
    result['latency_ms'] = round((time.monotonic() - start) * 1000)
    return result

def run():
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    previous = {}
    state_file = STATE / 'latest.json'
    if state_file.exists():
        try:
            previous = json.loads(state_file.read_text())
        except (OSError, ValueError):
            previous = {}
    checks = [check_endpoint(*endpoint) for endpoint in ENDPOINTS]
    backup = {'name': 'offsite_backup_age', 'passed': False}
    try:
        snapshots = [p.stat().st_mtime for p in (REPOSITORY / 'snapshots').iterdir() if p.is_file()]
        age = max(0, time.time() - max(snapshots)) if snapshots else None
        backup.update(age_seconds=round(age) if age is not None else None, passed=age is not None and age <= 8 * 3600)
    except OSError as error:
        backup['error_type'] = type(error).__name__
    checks.append(backup)
    try:
        capacity = shutil.disk_usage(REPOSITORY.parent)
        checks.append({'name': 'backup_disk_capacity', 'free_bytes': capacity.free, 'passed': capacity.free >= max(2 * 1024**3, capacity.total // 10)})
    except OSError as error:
        checks.append({'name': 'backup_disk_capacity', 'passed': False, 'error_type': type(error).__name__})
    passed = all(check['passed'] for check in checks)
    result = {
        'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'status': 'ok' if passed else 'degraded',
        'consecutive_failures': 0 if passed else previous.get('consecutive_failures', 0) + 1,
        'checks': checks,
        'notifications_configured': False,
    }
    try:
        settings = alerts.configuration()
        alerts.apply(previous, result, settings)
    except (OSError, ValueError, KeyError) as error:
        result['alert_configuration_error'] = type(error).__name__
    temporary = STATE / 'latest.json.partial'
    temporary.write_text(json.dumps(result, indent=2) + '\n')
    temporary.chmod(0o600)
    temporary.replace(state_file)
    if previous.get('status') != result['status']:
        with (STATE / 'events.jsonl').open('a') as events:
            events.write(json.dumps({'checked_at': result['checked_at'], 'status': result['status']}) + '\n')
    print(json.dumps(result))
    return 0 if passed else 1

if __name__ == '__main__':
    os.umask(0o077)
    raise SystemExit(run())
