#!/usr/bin/env python3
"""Do not accept a framework CLI error page with an accidental zero exit code."""
import json,pathlib,sys
kind,path=sys.argv[1:]
try:
    report=json.loads(pathlib.Path(path).read_text())
    if kind=='seed':
        assert report['synthetic_seed']=='ready' and report['organizations']==2 and report['actors']==8
        assert report['credentials_printed'] is False
        print('PASS synthetic seed: 2 organizations, 8 actors, no credentials printed')
    else:
        minimum={'delivery':80,'otp':29,'place':25,'socket':53,'socket-transport':17,'proof-files':34,'driver-comments':30,'organization-chat':60,'chat-pagination':34,'push-devices':37,'driver-reports':114,'diagnostic-access':19,'dashboard-metrics':8,'resource-crud':218}[kind]
        checks=report['checks']
        assert report['environment']==('isolated-synthetic-socket' if kind=='socket-transport' else 'isolated-synthetic-mysql') and isinstance(checks,list) and len(checks)>=minimum
        assert len({c['name'] for c in checks})==len(checks)
        failed=[{'name':c['name'],'http':c.get('http')} for c in checks if c.get('passed') is not True]
        if failed:
            print(json.dumps({'contract':kind,'failed_checks':failed}));raise AssertionError('Required contract checks failed')
        assert report['failed']==0 and report['passed']==len(checks)
        print(json.dumps({'contract':kind,'passed':len(checks),'failed':0}))
except (OSError,ValueError,KeyError,TypeError,AssertionError) as error:
    # Never echo malformed framework output, which may include request/config data.
    raise SystemExit('Invalid or failing '+kind+' QA report: '+type(error).__name__)
