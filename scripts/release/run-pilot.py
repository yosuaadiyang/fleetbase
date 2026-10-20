import argparse, json, pathlib, subprocess, time, shutil, os
parser=argparse.ArgumentParser(description='Guarded synthetic-only 10-driver Octane pilot')
parser.add_argument('--stage', type=pathlib.Path, required=True, help='Private stage containing qa.env, qa-source and octane.yml')
stage=parser.parse_args().stage.resolve()
os.chdir(stage/'qa-source')
compose=['docker','compose','--env-file',str(stage/'qa.env'),'-f','docker/release-qa/compose.yml','-f',str(stage/'octane.yml')]
os.umask(0o077)
resources=[]; reason=None; process=None

def capacity():
    values=dict((line.split(':')[0],int(line.split()[1])) for line in pathlib.Path('/proc/meminfo').read_text().splitlines() if line.startswith(('MemAvailable:','SwapFree:')))
    available=values['MemAvailable']//1024
    load=float(pathlib.Path('/proc/loadavg').read_text().split()[0])
    free=shutil.disk_usage(stage).free//(1024*1024)
    pressure=float(pathlib.Path('/proc/pressure/memory').read_text().splitlines()[0].split('avg10=')[1].split()[0])
    result={'time':time.time(),'available_mb':available,'load_1m':load,'free_disk_mb':free,'memory_pressure_avg10':pressure}
    resources.append(result)
    return available>=768 and load<8 and free>=5120 and pressure<10

try:
    if not capacity(): raise RuntimeError('Insufficient pilot headroom')
    raw=json.loads(subprocess.check_output(compose+['config','--format','json']))
    app=raw['services']['application']; env=app['environment']
    assert raw['name']=='contrust-release-qa' and env['CONTRUST_RELEASE_QA']=='synthetic-only' and env['DB_DATABASE']=='contrust_release_qa'
    assert env['MAIL_MAILER']=='log' and env['BROADCAST_DRIVER']=='log' and not env['BREVO_API_KEY']
    assert raw['networks']['isolated']['internal'] is True and all(x['host_ip']=='127.0.0.1' for x in app['ports'])
    assert app['command'][2]=='octane:frankenphp' and '--workers=2' in app['command']
    with (stage/'pilot-startup.log').open('w') as log:
        subprocess.run(compose+['up','-d','--wait','--wait-timeout','120','--pull','never'],stdout=log,stderr=subprocess.STDOUT,check=True,timeout=180)
    with (stage/'pilot-http.log').open('w') as log:
        process=subprocess.Popen(compose+['exec','-T','application','php','/opt/contrust-release-fixtures/pilot-http-load.php'],stdout=log,stderr=subprocess.STDOUT)
        start=time.monotonic()
        while process.poll() is None:
            if not capacity(): reason='capacity_guard';process.terminate();break
            if time.monotonic()-start>720: reason='deadline';process.terminate();break
            time.sleep(5)
        status=process.wait(timeout=20)
    if status: reason=reason or 'pilot_failed'
    else:
        lines=(stage/'pilot-http.log').read_text().splitlines()
        result=next(json.loads(line) for line in reversed(lines) if line.startswith('{'))
        assert result['environment']=='isolated-synthetic-octane' and result['production_data_used'] is False
        (stage/'pilot-result.json').write_text(json.dumps(result,indent=2)+'\n')
except Exception as error:
    reason=reason or type(error).__name__
finally:
    if process and process.poll() is None: process.terminate()
    subprocess.run(compose+['stop','application','database','cache'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=60)
    (stage/'pilot-resources.json').write_text(json.dumps({'samples':resources,'abort_reason':reason,'runtime':'FrankenPHP Octane','workers':2,'production_changed':False},indent=2)+'\n')
    print(json.dumps({'pilot_execution': 'finished' if reason is None else 'failed','reason':reason,'resource_samples':len(resources),'production_changed':False}))
raise SystemExit(0 if reason is None else 1)
