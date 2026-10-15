"""Optional Brevo alerts. No config means no outbound notification attempts."""
import json, os, pathlib, stat, urllib.request
CONFIG = pathlib.Path('/etc/contrust-monitor/alerts.json')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None

def private_text(path):
    path=pathlib.Path(path); meta=path.stat()
    if meta.st_uid != os.geteuid() or stat.S_IMODE(meta.st_mode) != 0o600:
        raise PermissionError('Alert configuration must be private to the service user')
    return path.read_text().strip()

def configuration():
    if not CONFIG.exists(): return None
    config=json.loads(private_text(CONFIG))
    key=private_text(config['api_key_file'])
    if not key or '@' not in config['recipient'] or '@' not in config['sender']:
        raise ValueError('Incomplete alert configuration')
    return config,key

def send(kind, result, settings, opener=None):
    config,key=settings
    degraded=kind=='incident'
    subject='Contrust: '+('monitoring test' if kind=='test' else ('service check failed' if degraded else 'service checks recovered'))
    lines=[subject, 'Checked at: '+result['checked_at']]
    for check in result['checks']:
        lines.append(check['name']+': '+('OK' if check['passed'] else 'FAILED'))
    body={'sender':{'email':config['sender'],'name':'Contrust Monitor'},'to':[{'email':config['recipient']}],
          'subject':subject,'textContent':'\n'.join(lines)}
    request=urllib.request.Request('https://api.brevo.com/v3/smtp/email',data=json.dumps(body).encode(),
        headers={'api-key':key,'Content-Type':'application/json','Accept':'application/json'},method='POST')
    opener=opener or urllib.request.build_opener(NoRedirect()).open
    try:
        with opener(request,timeout=8) as response:
            # Acceptance is not inbox delivery; do not log the provider body/key.
            return {'accepted':response.status in (200,201,202),'http':response.status}
    except Exception as error:
        out={'accepted':False,'error_type':type(error).__name__}
        if hasattr(error,'code'):out['http']=error.code
        if hasattr(error,'close'):error.close()
        return out

def apply(previous,result,settings,sender=send):
    result['notifications_configured']=settings is not None
    notified=bool(previous.get('incident_notified',False))
    result['incident_notified']=notified
    if settings is None:return
    kind='recovery' if result['status']=='ok' and notified else ('incident' if result['consecutive_failures']>=2 and not notified else None)
    if kind is None:return
    outcome=sender(kind,result,settings)
    result['alert_delivery']={'kind':kind,**outcome}
    if outcome.get('accepted'):result['incident_notified']=kind=='incident'
