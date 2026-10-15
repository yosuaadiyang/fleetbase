#!/usr/bin/env python3
"""Generate synthetic QA secrets locally; never read or copy production secrets."""
import argparse, base64, json, os, pathlib, secrets, subprocess
p=argparse.ArgumentParser();p.add_argument('--output',required=True);p.add_argument('--api-image',default='contrust-api:release-20261015-driver');args=p.parse_args()
out=pathlib.Path(args.output).resolve()
if out.exists():
 raise SystemExit('Refusing to replace existing QA environment')
images={
 'CONTRUST_QA_API_IMAGE':args.api_image,
 'CONTRUST_QA_DB_IMAGE':'mysql:8.0-oracle',
 'CONTRUST_QA_REDIS_IMAGE':'redis:7-alpine',
 'CONTRUST_QA_SOCKET_IMAGE':'contrust-socket:release-qa',
}
values={key:subprocess.check_output(['docker','image','inspect','--format','{{.Id}}',tag],text=True).strip() for key,tag in images.items()}
if any(not v.startswith('sha256:') or len(v)!=71 for v in values.values()):
 raise SystemExit('Invalid local image digest')
values.update(CONTRUST_QA_DB_PASSWORD=secrets.token_hex(24),CONTRUST_QA_ROOT_PASSWORD=secrets.token_hex(24),CONTRUST_QA_APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode())
out.parent.mkdir(parents=True,exist_ok=True)
fd=os.open(out,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
with os.fdopen(fd,'w') as f:
 for key,value in values.items():f.write(key+'='+value+'\n')
print(json.dumps({'qa_env_created':True,'production_env_read':False,'secret_values_printed':False}))
