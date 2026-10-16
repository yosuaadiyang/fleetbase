#!/usr/bin/env python3
"""Check the resolved Compose mount list and start that exact bounded socket runtime."""
import json,os,subprocess,sys,time,tempfile,pathlib
image=sys.argv[1]
# Compose versions differ on whether --no-env-resolution still checks file existence.
# Always supply a private synthetic env file, never a deployed publisher credential.
with tempfile.TemporaryDirectory(prefix='contrust-socket-config-') as folder:
 envfile=pathlib.Path(folder)/'publisher.env'
 envfile.write_text('CONTRUST_SOCKET_PUBLISHER_KEY='+'0'*64+'\n');envfile.chmod(0o600)
 environment={**os.environ,'CONTRUST_SOCKET_IMAGE':image,'CONTRUST_SOCKET_PUBLISHER_ENV_FILE':str(envfile)}
 plan=json.loads(subprocess.check_output(['docker','compose','-f','docker-compose.yml','-f','docker-compose.socket-security.yml','config','--no-env-resolution','--format','json'],env=environment,text=True))
socket=plan['services']['socket']
assert socket['read_only'] is True and int(socket['mem_limit'])<=192*1024**2
assert socket['security_opt']==['no-new-privileges:true'] and socket['cap_drop']==['ALL']
mounts=socket['tmpfs']
assert mounts==['/tmp:size=8m,noexec,nosuid'], 'tmpfs options must be one absolute mount entry'
name='contrust-socket-runtime-smoke'
command=['docker','run','-d','--name',name,'--network','none','--read-only','--memory','192m','--cpus','0.5','--pids-limit','96','--cap-drop','ALL','--security-opt','no-new-privileges:true','-e','CONTRUST_SOCKET_PUBLISHER_KEY='+'0'*64]
for mount in mounts:command.extend(['--tmpfs',mount])
try:
 subprocess.run(command+[image],check=True,stdout=subprocess.DEVNULL)
 for _ in range(20):
  result=subprocess.run(['docker','exec',name,'node','-e',"require('http').get('http://127.0.0.1:8000/health-check',r=>process.exit(r.statusCode===200?0:1)).on('error',()=>process.exit(1))"],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  if result.returncode==0:break
  time.sleep(0.25)
 else:raise RuntimeError('Secured socket runtime did not become healthy')
 print('PASS resolved socket Compose mounts and actual read-only bounded container startup; no external network')
finally:
 subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
