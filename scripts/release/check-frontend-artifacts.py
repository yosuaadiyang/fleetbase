#!/usr/bin/env python3
"""Reject runtime template errors that Ember can defer into a successful build."""
from pathlib import Path
import json
import os
import re
import sys

root = Path(sys.argv[1])
if not root.is_dir() or root.is_symlink():
    raise SystemExit('Production artifact directory is missing or unsafe')
markers = (
    b'contains an `export { default }` re-export, but it has a co-located template',
    b'does not contain a `default export`. Did you forget to export the component class?',
)
files = list(root.rglob('*.js'))
if not files:
    raise SystemExit('No production JavaScript artifacts found')
failures = []
source_url = os.environ.get('SOURCE_CODE_URL', '').encode()
source_found = not source_url
for file in files:
    if file.is_symlink() or not file.is_file():
        raise SystemExit('Unsafe production JavaScript path')
    body = file.read_bytes()
    source_found = source_found or source_url in body
    if any(marker in body for marker in markers):
        modules = re.findall(rb'`([@A-Za-z0-9_./-]+\.js)` (?:contains an|does not contain)', body)
        failures.append({'asset': str(file.relative_to(root)), 'modules': sorted({m.decode() for m in modules})})
print(json.dumps({'production_javascript_files': len(files), 'deferred_template_errors': failures, 'exact_source_link_found': source_found}))
if failures or not source_found:
    raise SystemExit(1)
