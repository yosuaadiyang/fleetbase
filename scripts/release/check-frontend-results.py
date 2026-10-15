#!/usr/bin/env python3
"""Fail closed if a filtered suite silently executes zero/missing regressions."""
import pathlib,re,sys
s=pathlib.Path(sys.argv[1]).read_text()
def count(name):
 m=re.findall(r'^# '+name+r'\s+(\d+)\s*$',s,re.M)
 if not m:raise SystemExit('Missing TAP summary: '+name)
 return int(m[-1])
assert count('tests')>=13,'Insufficient regression coverage'
assert count('tests')==count('pass'),'Not all regressions passed'
assert all(count(x)==0 for x in ['fail','skip','todo']),'Skipped or failed regression'
for name in ['public audit','point serialization','load-runtime-config','intl locale loading','fleetbase-attribution']:
 assert any(line.startswith('ok ') and name in line for line in s.splitlines()),'Required suite missing: '+name
print('Frontend release regression gate passed:',count('pass'),'tests, zero skips')
